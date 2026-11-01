<?php

namespace App\Http\Resources\Client;

use App\Http\Resources\BusinessCategory as BusinessCategoryResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Resumen de un negocio para la app del cliente (listados y búsquedas).
 *
 * A diferencia de `App\Http\Resources\BusinessResource`, no serializa
 * colecciones pesadas (productos, tarjetas, promociones completas): sólo los
 * datos que necesita una tarjeta o un listado, y los contadores cuando el
 * controlador los pide con `withCount()`.
 *
 * `distance_km` y `has_card` sólo aparecen si el controlador los inyecta como
 * atributos del modelo (búsqueda por cercanía).
 *
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

            // Identidad visual para pintar la tarjeta del negocio.
            'logo_url' => $this->logo_url,
            'background_url' => $this->background_url,
            'stamp_icon_url' => $this->stamp_icon_url,
            'card_settings' => $this->defaultCardSettings(),

            // Contacto y ubicación.
            'phone' => $this->phone,
            'phone_numbers' => $this->phone_numbers ?? [],
            'email' => $this->email,
            'website' => $this->website,
            'address' => $this->address,
            'city' => $this->city,
            'country' => $this->country,
            'latitude' => $this->latitude,
            'longitude' => $this->longitude,

            'categories' => BusinessCategoryResource::collection($this->whenLoaded('categories')),

            // Contadores (sólo cuando el controlador usa withCount()).
            'loyalty_cards_count' => $this->whenCounted('loyaltyCards'),
            'active_loyalty_cards_count' => $this->whenCounted('activeLoyaltyCards'),
            'active_promotions_count' => $this->whenCounted('activePromotions'),

            // Búsqueda por cercanía.
            'distance_km' => $this->when($this->distance_km !== null, $this->distance_km),
            'has_card' => $this->when($this->has_card !== null, (bool) $this->has_card),

            'is_active' => (bool) $this->is_active,
        ];
    }
}
