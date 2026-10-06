<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Models\Reward
 */
class RewardResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'business_id' => $this->business_id,
            'loyalty_card_id' => $this->loyalty_card_id,
            'name' => $this->name,
            'description' => $this->description,
            'image_url' => $this->image_url,
            'reward_type' => $this->reward_type->value,
            'reward_type_label' => $this->reward_type->label(),
            'value' => $this->value,
            'required_stamps' => (int) $this->required_stamps,
            'stock' => $this->stock,
            'has_stock' => $this->hasStock(),
            'is_active' => (bool) $this->is_active,
            'redemptions_count' => $this->whenCounted('redemptions'),
            'loyalty_card' => new LoyaltyCardResource($this->whenLoaded('loyaltyCard')),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
