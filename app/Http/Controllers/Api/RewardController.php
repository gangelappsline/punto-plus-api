<?php

namespace App\Http\Controllers\Api;

use App\Enums\RewardType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Reward\StoreRewardRequest;
use App\Http\Requests\Reward\UpdateRewardRequest;
use App\Http\Resources\RewardResource;
use App\Models\LoyaltyCard;
use App\Models\Reward;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Laravel\Passport\Attributes\AuthorizeToken;

/**
 * Recompensas asociadas a cada tarjeta de fidelidad.
 */
#[AuthorizeToken(['negocio', 'admin'], anyScope: true)]
class RewardController extends Controller
{
    /**
     * GET /api/business/cards/{loyaltyCard}/rewards
     */
    public function index(Request $request, LoyaltyCard $loyaltyCard): AnonymousResourceCollection
    {
        $this->authorize('manageRewards', $loyaltyCard);

        $rewards = $loyaltyCard->rewards()
            ->when($request->has('is_active'), fn ($query) => $query->where('is_active', $request->boolean('is_active')))
            ->withCount('redemptions')
            ->orderBy('required_stamps')
            ->get();

        return RewardResource::collection($rewards);
    }

    /**
     * POST /api/business/cards/{loyaltyCard}/rewards
     */
    public function store(StoreRewardRequest $request, LoyaltyCard $loyaltyCard): JsonResponse
    {
        $this->authorize('manageRewards', $loyaltyCard);

        $data = $request->validated();

        $reward = $loyaltyCard->rewards()->create([
            'business_id' => $loyaltyCard->business_id,
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'reward_type' => $data['reward_type'] ?? RewardType::FreeProduct,
            'value' => $data['value'] ?? null,
            'required_stamps' => $data['required_stamps'] ?? $loyaltyCard->required_stamps,
            'stock' => $data['stock'] ?? null,
            'is_active' => $data['is_active'] ?? true,
        ]);

        return (new RewardResource($reward))->response()->setStatusCode(201);
    }

    /**
     * PUT /api/business/rewards/{reward}
     */
    public function update(UpdateRewardRequest $request, Reward $reward): RewardResource
    {
        $this->authorize('update', $reward);

        $reward->fill($request->validated())->save();

        return new RewardResource($reward->load('loyaltyCard'));
    }

    /**
     * DELETE /api/business/rewards/{reward}
     */
    public function destroy(Reward $reward): JsonResponse
    {
        $this->authorize('delete', $reward);

        $reward->delete();

        return response()->json(['message' => 'Recompensa eliminada.']);
    }
}
