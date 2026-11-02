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
use Illuminate\Validation\ValidationException;

/**
 * Recompensas asociadas a cada tarjeta de fidelidad.
 */
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
            ->orderedByThreshold()
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
        $isSpend = $loyaltyCard->isSpend();

        // El umbral se mide en visitas o en puntos según el tipo de tarjeta.
        $thresholdColumn = $isSpend ? 'points_required' : 'required_stamps';
        $threshold = $isSpend
            ? ($data['points_required'] ?? null)
            : ($data['required_stamps'] ?? $loyaltyCard->required_stamps);

        if ($threshold !== null && $loyaltyCard->rewards()->where($thresholdColumn, $threshold)->exists()) {
            throw ValidationException::withMessages([
                $thresholdColumn => $isSpend
                    ? 'Ya existe una recompensa con esos puntos.'
                    : 'Ya existe una recompensa con esas visitas.',
            ]);
        }

        $reward = $loyaltyCard->rewards()->create([
            'business_id' => $loyaltyCard->business_id,
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'reward_type' => $data['reward_type'] ?? RewardType::FreeProduct,
            'value' => $data['value'] ?? null,
            'required_stamps' => $isSpend ? null : $threshold,
            'points_required' => $isSpend ? $threshold : null,
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

        $data = $request->validated();

        // Se descarta el umbral que no corresponde al tipo de tarjeta.
        if ($reward->loyaltyCard?->isSpend()) {
            unset($data['required_stamps']);
        } else {
            unset($data['points_required']);
        }

        $reward->fill($data)->save();

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
