<?php

namespace App\Http\Controllers\Api;

use App\Enums\PromotionScheduleType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Promotion\StorePromotionRequest;
use App\Http\Requests\Promotion\UpdatePromotionRequest;
use App\Http\Resources\PromotionResource;
use App\Models\LoyaltyCard;
use App\Models\Promotion;
use App\Services\AssetService;
use App\Support\ImageInput;
use App\Support\PromotionSchedule;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use RuntimeException;

/**
 * Promociones que el negocio publica para atraer y retener clientes.
 *
 * El banner llega como imagen en base64 (data URI opcional): se convierte, se
 * guarda en el disco público y su ruta queda en `image_path`.
 */
class PromotionController extends Controller
{
    public function __construct(private readonly AssetService $assets)
    {
    }

    /**
     * GET /api/business/promotions
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $business = $this->businessOrFail($request);

        $this->authorize('viewAny', Promotion::class);

        $filters = $request->validate([
            'is_active' => ['nullable', 'boolean'],
            'loyalty_card_id' => ['nullable', 'uuid'],
            'schedule_type' => ['nullable', Rule::enum(PromotionScheduleType::class)],
            // Sólo las que se muestran en este momento (tipo + día + fecha + hora).
            'available_now' => ['nullable', 'boolean'],
            'search' => ['nullable', 'string', 'max:150'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:'.config('punto_plus.per_page.max')],
        ]);

        $promotions = $business->promotions()
            ->ofScheduleType($filters['schedule_type'] ?? null)
            ->when(array_key_exists('is_active', $filters), fn ($query) => $query->where('is_active', (bool) $filters['is_active']))
            ->when(filled($filters['loyalty_card_id'] ?? null), fn ($query) => $query->where('loyalty_card_id', $filters['loyalty_card_id']))
            ->when(filled($filters['search'] ?? null), fn ($query) => $query->where('title', 'like', '%'.$filters['search'].'%'))
            ->with('loyaltyCard')
            ->orderByDesc('created_at')
            ->paginate($this->perPage($request))
            ->withQueryString();

        if ($request->boolean('available_now')) {
            // Se filtra sobre la página actual: el cálculo depende del día y la hora.
            $promotions->setCollection(
                $promotions->getCollection()
                    ->filter(fn (Promotion $promotion): bool => $promotion->isAvailableNow())
                    ->values(),
            );
        }

        return PromotionResource::collection($promotions);
    }

    /**
     * POST /api/business/promotions
     */
    public function store(StorePromotionRequest $request): JsonResponse
    {
        $business = $this->businessOrFail($request);

        $this->authorize('create', Promotion::class);

        $data = $this->withSchedule($this->withValidatedCard($request->validated(), $business->getKey()));

        $promotion = DB::transaction(function () use ($business, $data): Promotion {
            $promotion = $business->promotions()->create($this->withoutBanner($data));

            $banner = $this->bannerAttributes($data, $promotion);

            if ($banner !== []) {
                $promotion->fill($banner)->save();
            }

            return $promotion;
        });

        return (new PromotionResource($promotion->load('loyaltyCard')))->response()->setStatusCode(201);
    }

    /**
     * GET /api/business/promotions/{promotion}
     */
    public function show(Promotion $promotion): PromotionResource
    {
        $this->authorize('view', $promotion);

        return new PromotionResource($promotion->load(['business', 'loyaltyCard']));
    }

    /**
     * PUT /api/business/promotions/{promotion}
     */
    public function update(UpdatePromotionRequest $request, Promotion $promotion): PromotionResource
    {
        $this->authorize('update', $promotion);

        $data = $this->withSchedule(
            $this->withValidatedCard($request->validated(), $promotion->business_id),
            $promotion,
        );

        $promotion->fill($this->withoutBanner($data));
        $promotion->fill($this->bannerAttributes($data, $promotion));
        $promotion->save();

        return new PromotionResource($promotion->load('loyaltyCard'));
    }

    /**
     * DELETE /api/business/promotions/{promotion}
     *
     * Es un borrado lógico: el banner se conserva para poder restaurarla.
     */
    public function destroy(Promotion $promotion): JsonResponse
    {
        $this->authorize('delete', $promotion);

        $promotion->delete();

        return response()->json(['message' => 'Promoción eliminada.']);
    }

    /**
     * Valida que la tarjeta indicada pertenece al negocio antes de guardar.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function withValidatedCard(array $data, string $businessId): array
    {
        if (! filled($data['loyalty_card_id'] ?? null)) {
            return $data;
        }

        $belongs = LoyaltyCard::whereKey($data['loyalty_card_id'])
            ->where('business_id', $businessId)
            ->exists();

        if (! $belongs) {
            throw ValidationException::withMessages([
                'loyalty_card_id' => 'Esa tarjeta no pertenece a tu negocio.',
            ]);
        }

        return $data;
    }

    /**
     * Normaliza la programación (días, fechas y horas) según el `schedule_type`.
     *
     * Si el front no envía `schedule_type`, se deduce: sin fechas ni horas es
     * una promoción de tiempo indefinido; con fechas, por rango de fechas; con
     * horas, por rango de hora.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function withSchedule(array $data, ?Promotion $existing = null): array
    {
        if ($existing !== null && ! PromotionSchedule::touches($data)) {
            return $data;
        }

        // Se mezcla con lo ya guardado para no perder campos que no se enviaron.
        $merged = array_merge(
            $existing?->only(['days_of_week', 'start_time', 'end_time', 'starts_at', 'ends_at']) ?? [],
            $data,
        );

        $scheduleType = PromotionSchedule::resolveType(
            $data['schedule_type'] ?? null,
            $merged,
            $existing?->schedule_type,
        );

        return array_merge($data, PromotionSchedule::attributes($merged, $scheduleType));
    }

    /**
     * El banner no es una columna: se traduce a `image_path` después de guardar
     * la promoción (la carpeta del disco público lleva su id).
     *
     * @param  array<string, mixed>  $data
     */
    private function withoutBanner(array $data): array
    {
        unset($data['banner'], $data['remove_banner']);

        return $data;
    }

    /**
     * Ruta de almacenamiento que debe quedar en `image_path` según el banner
     * enviado (`null` si no hay nada que cambiar).
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function bannerAttributes(array $data, Promotion $promotion): array
    {
        if (! array_key_exists('banner', $data) && ! array_key_exists('remove_banner', $data)) {
            return [];
        }

        if ((bool) ($data['remove_banner'] ?? false)) {
            $this->assets->deletePublicAsset($promotion->image_path);

            return ['image_path' => null];
        }

        $banner = $data['banner'] ?? null;

        if (! is_string($banner) || trim($banner) === '') {
            return [];
        }

        $banner = ImageInput::normalizeMarkdownLink(trim($banner));

        if (ImageInput::isHttpUrl($banner)) {
            // Al editar, el front puede reenviar la URL (o la ruta) del banner guardado.
            if ($this->isCurrentBanner($banner, $promotion)) {
                return [];
            }

            throw ValidationException::withMessages([
                'banner' => 'El banner debe enviarse como imagen en base64 (data URI o cadena base64).',
            ]);
        }

        try {
            $path = $this->assets->storePublicBase64Image(
                $banner,
                $this->assets->promotionBannerDirectory((string) $promotion->getKey()),
            );
        } catch (InvalidArgumentException|RuntimeException $exception) {
            throw ValidationException::withMessages(['banner' => $exception->getMessage()]);
        }

        $this->assets->deletePublicAsset($promotion->image_path);

        return ['image_path' => $path];
    }

    /**
     * ¿El valor enviado apunta al banner que la promoción ya tiene guardado?
     */
    private function isCurrentBanner(string $value, Promotion $promotion): bool
    {
        $currentPath = $promotion->image_path;

        if (blank($currentPath)) {
            return false;
        }

        return $value === $currentPath
            || $value === $promotion->image_url
            || str_ends_with($value, '/'.$currentPath);
    }
}
