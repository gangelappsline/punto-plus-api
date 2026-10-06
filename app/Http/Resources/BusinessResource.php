<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Models\Business
 */
class BusinessResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'description' => $this->description,
            'category' => $this->category,
            'phone' => $this->phone,
            'email' => $this->email,
            'website' => $this->website,
            'address' => $this->address,
            'city' => $this->city,
            'country' => $this->country,
            'latitude' => $this->latitude,
            'longitude' => $this->longitude,

            // Identidad visual (lo que el negocio configuró en BusinessController).
            'logo_url' => $this->logo_url,
            'background_url' => $this->background_url,
            'stamp_icon_url' => $this->stamp_icon_url,
            'card_settings' => $this->defaultCardSettings(),

            'is_active' => (bool) $this->is_active,
            'owner' => new UserResource($this->whenLoaded('owner')),
            'loyalty_cards' => LoyaltyCardResource::collection($this->whenLoaded('loyaltyCards')),
            'loyalty_cards_count' => $this->whenCounted('loyaltyCards'),
            'customers_count' => $this->whenCounted('customerCards'),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
