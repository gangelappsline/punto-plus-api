<?php

namespace App\Http\Controllers\Api\Client;

use App\Enums\DiscountType;
use App\Enums\PromotionScheduleType;
use App\Http\Controllers\Controller;
use App\Http\Resources\Client\PromotionResource;
use App\Models\CustomerCard;
use App\Models\Promotion;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

/**
 * Promociones para la app del cliente.
 *
 * Sólo se devuelven las promociones vigentes de los negocios con los que el
 * cliente **tiene o ha tenido** tarjeta de fidelidad (las tarjetas dadas de
 * baja se conservan con borrado lógico y también cuentan).
 */
class PromotionController extends Controller
{
    /**
     * GET /api/client/promotions
     *
     * Filtros: `business_id`, `search` (título o descripción), `discount_type`,
     * `per_page`.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $filters = $request->validate([
            'business_id' => ['nullable', 'uuid'],
            'search' => ['nullable', 'string', 'max:150'],
            'discount_type' => [
                'nullable',
                'string',
                Rule::in(array_map(fn (DiscountType $type): string => $type->value, DiscountType::cases())),
            ],
            'schedule_type' => ['nullable', Rule::enum(PromotionScheduleType::class)],
            // `true` devuelve sólo las que aplican en este momento (día, fecha y hora).
            'available_now' => ['nullable', 'boolean'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:'.config('punto_plus.per_page.max')],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);

        // `withTrashed()` incluye los programas de los que el cliente se dio de baja.
        $businessIds = CustomerCard::withTrashed()
            ->where('user_id', $request->user()->getAuthIdentifier())
            ->distinct()
            ->pluck('business_id');

        $promotions = Promotion::query()
            ->active()
            ->whereIn('business_id', $businessIds)
            ->whereHas('business', fn (Builder $query): Builder => $query->where('is_active', true))
            ->when(filled($filters['business_id'] ?? null), fn (Builder $query): Builder => $query->where('business_id', $filters['business_id']))
            ->when(filled($filters['search'] ?? null), function (Builder $query) use ($filters): void {
                $like = '%'.$filters['search'].'%';

                $query->where(function (Builder $query) use ($like): void {
                    $query->where('title', 'like', $like)
                        ->orWhere('description', 'like', $like);
                });
            })
            ->when(filled($filters['discount_type'] ?? null), fn (Builder $query): Builder => $query->where('discount_type', $filters['discount_type']))
            ->ofScheduleType($filters['schedule_type'] ?? null)
            ->with(['business', 'loyaltyCard'])
            ->orderByDesc('starts_at')
            ->orderByDesc('created_at')
            ->paginate($this->perPage($request))
            ->withQueryString();

        if ($request->boolean('available_now')) {
            $promotions->setCollection(
                $promotions->getCollection()
                    ->filter(fn (Promotion $promotion): bool => $promotion->isAvailableNow())
                    ->values(),
            );
        }

        return PromotionResource::collection($promotions);
    }
}
