<?php

namespace App\Http\Resources\Client;

use App\Http\Resources\LoyaltyCardResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Promoción vista desde la app del cliente: sus datos y un resumen del negocio
 * que la publica.
 *
 * @mixin \App\Models\Promotion
 */
class PromotionResource extends JsonResource
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
            'title' => $this->title,
            'description' => $this->description,
            'image_url' => $this->image_url,
            'image_path' => $this->image_path,
            'terms' => $this->terms,
            'discount_type' => $this->discount_type->value,
            'discount_type_label' => $this->discount_type->label(),
            'discount_value' => $this->discount_value,
            'code' => $this->code,

            // Cómo se muestra: indefinida, por fechas o por horas (hora feliz).
            'schedule_type' => $this->schedule_type->value,
            'schedule_type_label' => $this->schedule_type->label(),
            'schedule' => $this->schedule,
            'days_of_week' => $this->weekdays,
            'days_of_week_labels' => $this->weekday_labels,
            'start_time' => \App\Models\Promotion::formatTime($this->start_time),
            'end_time' => \App\Models\Promotion::formatTime($this->end_time),
            'starts_at' => $this->starts_at?->toIso8601String(),
            'ends_at' => $this->ends_at?->toIso8601String(),

            'is_active' => (bool) $this->is_active,
            'is_active_now' => $this->isActiveNow(),
            'is_available_now' => $this->isAvailableNow(),
            'max_redemptions' => $this->max_redemptions,
            'redemptions_count' => (int) $this->redemptions_count,

            'business' => new BusinessResource($this->whenLoaded('business')),
            'loyalty_card' => new LoyaltyCardResource($this->whenLoaded('loyaltyCard')),

            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
