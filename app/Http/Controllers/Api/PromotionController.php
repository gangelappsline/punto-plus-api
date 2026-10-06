<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Promotion\StorePromotionRequest;
use App\Http\Requests\Promotion\UpdatePromotionRequest;
use App\Http\Resources\PromotionResource;
use App\Models\CustomerCard;
use App\Models\LoyaltyCard;
use App\Models\Promotion;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\ValidationException;
use Laravel\Passport\Attributes\AuthorizeToken;

/**
 * Promociones que el negocio publica para atraer y retener clientes.
 */
#[AuthorizeToken(['negocio', 'admin'], anyScope: true)]
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
            'search' => ['nullable', 'string', 'max:150'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:'.config('punto_plus.per_page.max')],
        ]);

        $promotions = $business->promotions()
            ->when(array_key_exists('is_active', $filters), fn ($query) => $query->where('is_active', (bool) $filters['is_active']))
            ->when(filled($filters['loyalty_card_id'] ?? null), fn ($query) => $query->where('loyalty_card_id', $filters['loyalty_card_id']))
            ->when(filled($filters['search'] ?? null), fn ($query) => $query->where('title', 'like', '%'.$filters['search'].'%'))
            ->with('loyaltyCard')
            ->orderByDesc('created_at')
            ->paginate($this->perPage($request))
            ->withQueryString();

        return PromotionResource::collection($promotions);
    }

    /**
     * POST /api/business/promotions
     */
    public function store(StorePromotionRequest $request): JsonResponse
    {
        $business = $this->businessOrFail($request);

        $this->authorize('create', Promotion::class);

        $promotion = $business->promotions()->create(
            $this->withValidatedCard($request->validated(), $business->getKey())
        );

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

        $promotion->fill($this->withValidatedCard($request->validated(), $promotion->business_id))->save();

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
     * GET /api/customer/promotions — promociones vigentes de mis negocios.
     */
    #[AuthorizeToken(['cliente', 'negocio', 'admin'], anyScope: true)]
    public function forCustomer(Request $request): AnonymousResourceCollection
    {
        $filters = $request->validate([
            'business_id' => ['nullable', 'uuid'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:'.config('punto_plus.per_page.max')],
        ]);

        $businessIds = CustomerCard::query()
            ->where('user_id', $request->user()->getAuthIdentifier())
            ->distinct()
            ->pluck('business_id');

        $promotions = Promotion::query()
            ->active()
            ->whereIn('business_id', $businessIds)
            ->when(filled($filters['business_id'] ?? null), fn ($query) => $query->where('business_id', $filters['business_id']))
            ->with(['business', 'loyaltyCard'])
            ->orderByDesc('starts_at')
            ->paginate($this->perPage($request))
            ->withQueryString();

        return PromotionResource::collection($promotions);
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
}
