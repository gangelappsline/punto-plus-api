<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Recompensa de una tarjeta.
 *
 * `threshold` es el umbral en la unidad del programa: visitas
 * (`required_stamps`) o puntos (`points_required`).
 *
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

            // Umbral: uno de los dos está definido según el tipo de tarjeta.
            'required_stamps' => $this->required_stamps === null ? null : (int) $this->required_stamps,
            'points_required' => $this->points_required === null ? null : (int) $this->points_required,
            'threshold' => $this->points_required !== null
                ? (int) $this->points_required
                : ($this->required_stamps === null ? null : (int) $this->required_stamps),
            'threshold_unit' => $this->thresholdUnit(),

            'stock' => $this->stock,
            'has_stock' => $this->hasStock(),
            'is_active' => (bool) $this->is_active,
            'redemptions_count' => (int) $this->redemptions_count,
            'loyalty_card' => new LoyaltyCardResource($this->whenLoaded('loyaltyCard')),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
