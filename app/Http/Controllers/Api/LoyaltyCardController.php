<?php

namespace App\Http\Controllers\Api;

use App\Enums\CardPeriodType;
use App\Enums\LoyaltyCardType;
use App\Enums\RewardType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Business\UploadAssetRequest;
use App\Http\Requests\LoyaltyCard\StoreLoyaltyCardRequest;
use App\Http\Requests\LoyaltyCard\UpdateLoyaltyCardRequest;
use App\Http\Resources\LoyaltyCardResource;
use App\Models\CustomerCard;
use App\Models\LoyaltyCard;
use App\Models\Redemption;
use App\Models\Stamp;
use App\Services\AssetService;
use App\Services\QrCodeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Gestión de las tarjetas de fidelidad del negocio autenticado.
 */
class LoyaltyCardController extends Controller
{
    private const ASSET_FIELDS = [
        'logo' => 'logo_path',
        'background' => 'background_path',
        'stamp_icon' => 'stamp_icon_path',
    ];

    public function __construct(
        private readonly AssetService $assets,
        private readonly QrCodeService $qrCodes,
    ) {
    }

    /**
     * GET /api/business/cards
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $business = $this->businessOrFail($request);

        $this->authorize('viewAny', LoyaltyCard::class);

        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:120'],
            'is_active' => ['nullable', 'boolean'],
            'type' => ['nullable', Rule::enum(LoyaltyCardType::class)],
            'period_type' => ['nullable', Rule::enum(CardPeriodType::class)],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:'.config('punto_plus.per_page.max')],
        ]);

        $cards = $business->loyaltyCards()
            ->ofType($filters['type'] ?? null)
            ->when(
                filled($filters['period_type'] ?? null),
                fn ($query) => $query->where('period_type', $filters['period_type']),
            )
            ->when(
                filled($filters['search'] ?? null),
                fn ($query) => $query->where('name', 'like', '%'.$filters['search'].'%'),
            )
            ->when(
                array_key_exists('is_active', $filters),
                fn ($query) => $query->where('is_active', (bool) $filters['is_active']),
            )
            ->withCount(['rewards', 'customerCards', 'stamps'])
            ->orderByDesc('created_at')
            ->paginate($this->perPage($request))
            ->withQueryString();

        return LoyaltyCardResource::collection($cards);
    }

    /**
     * POST /api/business/cards
     */
    public function store(StoreLoyaltyCardRequest $request): JsonResponse
    {
        $business = $this->businessOrFail($request);

        $this->authorize('create', LoyaltyCard::class);

        $data = $request->validated();
        $rewards = $data['rewards'] ?? [];
        unset($data['rewards']);

        $type = LoyaltyCardType::tryFrom($data['type'] ?? '') ?? LoyaltyCardType::Visits;
        $periodType = CardPeriodType::tryFrom($data['period_type'] ?? '') ?? CardPeriodType::Unlimited;

        $this->assertThresholdsAreDistinct($rewards, $type);

        $inherit = $request->boolean('inherit_business_branding', true);
        $defaults = $business->defaultCardSettings();

        $card = DB::transaction(function () use ($business, $data, $rewards, $inherit, $defaults, $type, $periodType): LoyaltyCard {
            $settings = array_merge($defaults, $data['settings'] ?? []);

            $requiredStamps = $type->isVisits()
                ? ($data['required_stamps'] ?? $settings['required_stamps'] ?? config('punto_plus.cards.default_required_stamps'))
                : null;

            $attributes = [
                'name' => $data['name'],
                'description' => $data['description'] ?? null,

                // Tipo de programa y su configuración.
                'type' => $type->value,
                'required_stamps' => $requiredStamps,
                'currency' => $type->isSpend()
                    ? strtoupper($data['currency'] ?? config('punto_plus.cards.default_currency'))
                    : null,
                'spend_amount_per_block' => $type->isSpend() ? ($data['spend_amount_per_block'] ?? null) : null,
                'points_per_block' => $type->isSpend() ? ($data['points_per_block'] ?? null) : null,

                // Vigencia del ciclo.
                'period_type' => $periodType->value,
                'period_days' => $periodType->requiresDays() ? (int) $data['period_days'] : null,

                'reward_description' => $data['reward_description'] ?? null,
                'terms' => $data['terms'] ?? null,
                'primary_color' => $data['primary_color'] ?? $settings['primary_color'] ?? null,
                'secondary_color' => $data['secondary_color'] ?? $settings['secondary_color'] ?? null,
                'text_color' => $data['text_color'] ?? $settings['text_color'] ?? null,
                'is_active' => $data['is_active'] ?? true,
                'is_public' => $data['is_public'] ?? true,
                'expires_at' => $data['expires_at'] ?? null,
                'settings' => $settings,
                'logo_path' => $inherit ? $business->logo_path : null,
                'background_path' => $inherit ? $business->background_path : null,
                'stamp_icon_path' => $inherit ? $business->stamp_icon_path : null,
            ];

            /** @var LoyaltyCard $card */
            $card = $business->loyaltyCards()->create($attributes);

            foreach ($rewards as $reward) {
                $card->rewards()->create([
                    'business_id' => $business->getKey(),
                    'name' => $reward['name'],
                    'description' => $reward['description'] ?? null,
                    'reward_type' => $reward['reward_type'] ?? RewardType::FreeProduct,
                    'value' => $reward['value'] ?? null,
                    'required_stamps' => $type->isVisits()
                        ? ($reward['required_stamps'] ?? $requiredStamps)
                        : null,
                    'points_required' => $type->isSpend() ? ($reward['points_required'] ?? null) : null,
                    'stock' => $reward['stock'] ?? null,
                    'is_active' => $reward['is_active'] ?? true,
                ]);
            }

            return $card;
        });

        return (new LoyaltyCardResource(
            $card->load('rewards')->loadCount(['rewards', 'customerCards', 'stamps'])
        ))->response()->setStatusCode(201);
    }

    /**
     * GET /api/business/cards/{loyaltyCard}
     */
    public function show(LoyaltyCard $loyaltyCard): LoyaltyCardResource
    {
        $this->authorize('view', $loyaltyCard);

        return new LoyaltyCardResource(
            $loyaltyCard->load(['business', 'rewards'])->loadCount(['rewards', 'customerCards', 'stamps'])
        );
    }

    /**
     * PUT /api/business/cards/{loyaltyCard}
     */
    public function update(UpdateLoyaltyCardRequest $request, LoyaltyCard $loyaltyCard): LoyaltyCardResource
    {
        $this->authorize('update', $loyaltyCard);

        $loyaltyCard->fill($this->normalizeCardAttributes($loyaltyCard, $request->validated()))->save();

        return new LoyaltyCardResource(
            $loyaltyCard->load('rewards')->loadCount(['rewards', 'customerCards', 'stamps'])
        );
    }

    /**
     * DELETE /api/business/cards/{loyaltyCard}
     *
     * La tarjeta se desactiva y se borra lógicamente: los clientes conservan su
     * historial de sellos, pero el programa deja de estar disponible.
     */
    public function destroy(LoyaltyCard $loyaltyCard): JsonResponse
    {
        $this->authorize('delete', $loyaltyCard);

        $loyaltyCard->forceFill(['is_active' => false])->save();
        $loyaltyCard->delete();

        return response()->json([
            'message' => 'Tarjeta archivada. Los clientes ya no podrán unirse al programa.',
        ]);
    }

    /**
     * POST /api/business/cards/{loyaltyCard}/upload-assets
     */
    public function uploadAssets(UploadAssetRequest $request, LoyaltyCard $loyaltyCard): LoyaltyCardResource
    {
        $this->authorize('manageAssets', $loyaltyCard);

        $field = self::ASSET_FIELDS[$request->validated('type')];

        $loyaltyCard->{$field} = $this->assets->replace(
            $loyaltyCard->{$field},
            $request->file('file'),
            $this->assets->loyaltyCardDirectory($loyaltyCard->getKey()),
        );

        $loyaltyCard->save();

        return new LoyaltyCardResource($loyaltyCard);
    }

    /**
     * POST /api/business/cards/{loyaltyCard}/regenerate-join-code
     */
    public function regenerateJoinCode(LoyaltyCard $loyaltyCard): JsonResponse
    {
        $this->authorize('update', $loyaltyCard);

        $loyaltyCard->forceFill(['join_code' => LoyaltyCard::generateJoinCode()])->save();

        return response()->json([
            'message' => 'Código de alta regenerado. Imprime el nuevo QR.',
            'join_code' => $loyaltyCard->join_code,
            'qr' => $this->qrCodes->forLoyaltyCard($loyaltyCard),
        ]);
    }

    /**
     * POST /api/business/cards/{loyaltyCard}/duplicate
     */
    public function duplicate(LoyaltyCard $loyaltyCard): JsonResponse
    {
        $this->authorize('update', $loyaltyCard);

        $copy = DB::transaction(function () use ($loyaltyCard): LoyaltyCard {
            /** @var LoyaltyCard $copy */
            $copy = $loyaltyCard->replicate(['join_code', 'slug']);
            $copy->name = $loyaltyCard->name.' (copia)';
            $copy->join_code = LoyaltyCard::generateJoinCode();
            $copy->is_active = false;
            $copy->save();

            foreach ($loyaltyCard->rewards as $reward) {
                $copy->rewards()->create($reward->only([
                    'name', 'description', 'reward_type', 'value',
                    'required_stamps', 'points_required', 'stock', 'is_active',
                ]) + ['business_id' => $loyaltyCard->business_id]);
            }

            return $copy;
        });

        return (new LoyaltyCardResource($copy->load('rewards')))->response()->setStatusCode(201);
    }

    /**
     * GET /api/business/cards/{loyaltyCard}/qr
     */
    public function qr(LoyaltyCard $loyaltyCard): JsonResponse
    {
        $this->authorize('view', $loyaltyCard);

        return response()->json($this->qrCodes->forLoyaltyCard($loyaltyCard));
    }

    /**
     * GET /api/business/cards/{loyaltyCard}/stats
     */
    public function stats(LoyaltyCard $loyaltyCard): JsonResponse
    {
        $this->authorize('viewStats', $loyaltyCard);

        $cards = CustomerCard::where('loyalty_card_id', $loyaltyCard->getKey());
        $stamps = Stamp::where('loyalty_card_id', $loyaltyCard->getKey());

        $earned = (int) (clone $cards)->sum('rewards_earned');
        $redeemed = (int) (clone $cards)->sum('rewards_redeemed');

        $validRedemptions = Redemption::where('business_id', $loyaltyCard->business_id)
            ->where('status', 'completed')
            ->whereHas('reward', fn ($query) => $query->where('loyalty_card_id', $loyaltyCard->getKey()));

        return response()->json([
            'loyalty_card_id' => $loyaltyCard->getKey(),
            'type' => $loyaltyCard->type->value,
            'progress_unit' => $loyaltyCard->progressUnit(),
            'customers' => (clone $cards)->count(),
            'customers_completed' => $loyaltyCard->isSpend()
                ? 0
                : (clone $cards)->where('status', 'completed')->count(),
            'stamps_total' => $loyaltyCard->isSpend() ? 0 : (clone $stamps)->count(),
            'stamps_last_30_days' => $loyaltyCard->isSpend()
                ? 0
                : (clone $stamps)->where('stamped_at', '>=', now()->subDays(30))->count(),
            'points_awarded' => $loyaltyCard->isSpend() ? (int) (clone $cards)->sum('total_points_earned') : 0,
            'points_balance' => $loyaltyCard->isSpend() ? (int) (clone $cards)->sum('points_count') : 0,
            'rewards_earned' => $earned,
            'rewards_redeemed' => $redeemed,
            'rewards_available' => max(0, $earned - $redeemed),
            'redemptions_completed' => (clone $validRedemptions)->count(),
            'points_redeemed' => $loyaltyCard->isSpend() ? (int) (clone $validRedemptions)->sum('points_used') : 0,
            'rewards_defined' => $loyaltyCard->rewards()->count(),
            'generated_at' => now()->toIso8601String(),
        ]);
    }

    /**
     * Normaliza los campos que dependen del tipo de tarjeta al actualizar.
     *
     * Evita que queden datos contradictorios: una tarjeta por consumo no guarda
     * `required_stamps` y una por visitas no guarda la configuración de puntos.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function normalizeCardAttributes(LoyaltyCard $card, array $data): array
    {
        if (array_key_exists('type', $data)) {
            $type = LoyaltyCardType::tryFrom((string) $data['type']) ?? $card->type;

            if ($type->isVisits()) {
                $data['currency'] = null;
                $data['spend_amount_per_block'] = null;
                $data['points_per_block'] = null;
                $data['required_stamps'] ??= config('punto_plus.cards.default_required_stamps');
            } else {
                $data['required_stamps'] = null;
            }
        }

        if (array_key_exists('currency', $data) && filled($data['currency'])) {
            $data['currency'] = strtoupper((string) $data['currency']);
        }

        if (array_key_exists('period_type', $data)) {
            $periodType = CardPeriodType::tryFrom((string) $data['period_type']) ?? CardPeriodType::Unlimited;

            if ($periodType->requiresDays()) {
                $data['period_days'] ??= $card->period_days ?? 30;
            } else {
                $data['period_days'] = null;
            }
        } elseif (array_key_exists('period_days', $data) && $data['period_days'] !== null) {
            // Enviar sólo `period_days` implica que el ciclo dura ese lapso.
            $data['period_type'] = CardPeriodType::Days->value;
        }

        return $data;
    }

    /**
     * Las recompensas de una tarjeta no pueden compartir umbral.
     *
     * @param  array<int, array<string, mixed>>  $rewards
     */
    private function assertThresholdsAreDistinct(array $rewards, LoyaltyCardType $type): void
    {
        $field = $type->isSpend() ? 'points_required' : 'required_stamps';

        $thresholds = collect($rewards)
            ->pluck($field)
            ->filter(fn ($value): bool => $value !== null && $value !== '')
            ->map(fn ($value): int => (int) $value);

        if ($thresholds->duplicates()->isNotEmpty()) {
            throw ValidationException::withMessages([
                "rewards.*.{$field}" => $type->isSpend()
                    ? 'Dos recompensas no pueden pedir los mismos puntos.'
                    : 'Dos recompensas no pueden pedir las mismas visitas.',
            ]);
        }
    }
}
