<?php

namespace App\Http\Controllers\Api;

use App\Enums\PromotionScheduleType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Promotion\StorePromotionRequest;
use App\Http\Requests\Promotion\UpdatePromotionRequest;
use App\Http\Resources\PromotionResource;
use App\Models\LoyaltyCard;
use App\Models\Promotion;
use App\Support\PromotionSchedule;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Promociones que el negocio publica para atraer y retener clientes.
 */
class PromotionController extends Controller
{
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

        $promotion = $business->promotions()->create($data);

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

        $promotion->fill($data)->save();

        return new PromotionResource($promotion->load('loyaltyCard'));
    }

    /**
     * DELETE /api/business/promotions/{promotion}
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
     * Así la base de datos no guarda campos que no aplican: una promoción
     * indefinida no tiene fechas y una de rango de fechas no tiene horas.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function withSchedule(array $data, ?Promotion $existing = null): array
    {
        $scheduleType = PromotionScheduleType::tryFrom((string) ($data['schedule_type'] ?? ''))
            ?? $existing?->schedule_type
            ?? PromotionScheduleType::DateRange;

        $touchesSchedule = $existing === null
            || array_key_exists('schedule_type', $data)
            || array_intersect(array_keys($data), ['days_of_week', 'start_time', 'end_time', 'starts_at', 'ends_at']) !== [];

        if (! $touchesSchedule) {
            return $data;
        }

        // Se mezcla con lo ya guardado para no perder campos que no se enviaron.
        $merged = array_merge(
            $existing?->only(['days_of_week', 'start_time', 'end_time', 'starts_at', 'ends_at']) ?? [],
            $data,
        );

        return array_merge($data, PromotionSchedule::attributes($merged, $scheduleType));
    }
}
