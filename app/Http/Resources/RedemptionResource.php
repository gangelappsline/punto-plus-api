<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Models\Redemption
 */
class RedemptionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'stamps_used' => (int) $this->stamps_used,
            'redeemed_at' => $this->redeemed_at?->toIso8601String(),
            'approved_at' => $this->approved_at?->toIso8601String(),
            'notes' => $this->notes,
            'customer_card_id' => $this->customer_card_id,
            'reward_id' => $this->reward_id,
            'business_id' => $this->business_id,
            'user_id' => $this->user_id,
            'reward' => new RewardResource($this->whenLoaded('reward')),
            'customer_card' => new CustomerCardResource($this->whenLoaded('customerCard')),
            'business' => new BusinessResource($this->whenLoaded('business')),
            'user' => new UserResource($this->whenLoaded('user')),
            'approved_by' => new UserResource($this->whenLoaded('approvedBy')),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
