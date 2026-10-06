<?php

namespace App\Http\Controllers\Api;

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
use Laravel\Passport\Attributes\AuthorizeToken;

/**
 * Gestión de las tarjetas de fidelidad del negocio autenticado.
 */
#[AuthorizeToken(['negocio', 'admin'], anyScope: true)]
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
            'per_page' => ['nullable', 'integer', 'min:1', 'max:'.config('punto_plus.per_page.max')],
        ]);

        $cards = $business->loyaltyCards()
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

        $inherit = $request->boolean('inherit_business_branding', true);
        $defaults = $business->defaultCardSettings();

        $card = DB::transaction(function () use ($business, $data, $rewards, $inherit, $defaults): LoyaltyCard {
            $settings = array_merge($defaults, $data['settings'] ?? []);

            $attributes = [
                'name' => $data['name'],
                'description' => $data['description'] ?? null,
                'required_stamps' => $data['required_stamps'] ?? $settings['required_stamps'] ?? config('punto_plus.cards.default_required_stamps'),
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
                    'required_stamps' => $reward['required_stamps'] ?? $card->required_stamps,
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

        $loyaltyCard->fill($request->validated())->save();

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
                    'name', 'description', 'reward_type', 'value', 'required_stamps', 'stock', 'is_active',
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

        return response()->json([
            'loyalty_card_id' => $loyaltyCard->getKey(),
            'customers' => (clone $cards)->count(),
            'customers_completed' => (clone $cards)->where('status', 'completed')->count(),
            'stamps_total' => (clone $stamps)->count(),
            'stamps_last_30_days' => (clone $stamps)->where('stamped_at', '>=', now()->subDays(30))->count(),
            'rewards_earned' => $earned,
            'rewards_redeemed' => $redeemed,
            'rewards_available' => max(0, $earned - $redeemed),
            'redemptions_completed' => Redemption::where('business_id', $loyaltyCard->business_id)
                ->where('status', 'completed')
                ->whereHas('reward', fn ($query) => $query->where('loyalty_card_id', $loyaltyCard->getKey()))
                ->count(),
            'rewards_defined' => $loyaltyCard->rewards()->count(),
            'generated_at' => now()->toIso8601String(),
        ]);
    }
}
