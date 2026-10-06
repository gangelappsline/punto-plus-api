<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Models\Stamp
 */
class StampResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'customer_card_id' => $this->customer_card_id,
            'business_id' => $this->business_id,
            'loyalty_card_id' => $this->loyalty_card_id,
            'source' => $this->source->value,
            'source_label' => $this->source->label(),
            'purchase_amount' => $this->purchase_amount,
            'notes' => $this->notes,
            'stamped_at' => $this->stamped_at?->toIso8601String(),
            'registered_by' => new UserResource($this->whenLoaded('registeredBy')),
            'customer_card' => new CustomerCardResource($this->whenLoaded('customerCard')),
            'business' => new BusinessResource($this->whenLoaded('business')),
            'loyalty_card' => new LoyaltyCardResource($this->whenLoaded('loyaltyCard')),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
