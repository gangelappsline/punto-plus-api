<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Tarjeta del cliente con su progreso actual.
 *
 * @mixin \App\Models\CustomerCard
 */
class CustomerCardResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'user_id' => $this->user_id,
            'loyalty_card_id' => $this->loyalty_card_id,
            'business_id' => $this->business_id,

            // --- Progreso ---
            'stamps_count' => (int) $this->stamps_count,
            'required_stamps' => (int) $this->required_stamps,
            'stamps_remaining' => (int) $this->stamps_remaining,
            'progress_percentage' => $this->progress_percentage,
            'progress' => $this->progress,

            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'rewards_earned' => (int) $this->rewards_earned,
            'rewards_redeemed' => (int) $this->rewards_redeemed,
            'total_stamps_earned' => (int) $this->total_stamps_earned,
            'completed_at' => $this->completed_at?->toIso8601String(),
            'last_stamp_at' => $this->last_stamp_at?->toIso8601String(),
            'expires_at' => $this->expires_at?->toIso8601String(),

            'loyalty_card' => new LoyaltyCardResource($this->whenLoaded('loyaltyCard')),
            'business' => new BusinessResource($this->whenLoaded('business')),
            'user' => new UserResource($this->whenLoaded('user')),
            'stamps' => StampResource::collection($this->whenLoaded('stamps')),
            'stamps_count_total' => $this->whenCounted('stamps'),
            'redemptions' => RedemptionResource::collection($this->whenLoaded('redemptions')),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
