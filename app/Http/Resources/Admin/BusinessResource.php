<?php

namespace App\Http\Resources\Admin;

use App\Http\Resources\BusinessCategory as BusinessCategoryResource;
use App\Http\Resources\LoyaltyCardResource;
use App\Http\Resources\ProductResource;
use App\Http\Resources\PromotionResource;
use App\Http\Resources\RewardResource;
use App\Http\Resources\UserResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Negocio visto desde el panel de administración de Punto Plus.
 *
 * A diferencia de `App\Http\Resources\BusinessResource` (que expone el negocio
 * al dueño y al público), aquí no se cargan colecciones pesadas por defecto:
 * los productos, tarjetas, promociones y recompensas sólo aparecen cuando el
 * controlador los ha cargado con `load()`, y los totales se resuelven con
 * `withCount()` para evitar N+1 en el listado.
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
            'email' => $this->email,
            'phone' => $this->phone,
            'phone_numbers' => $this->phone_numbers ?? [],
            'website' => $this->website,
            'address' => $this->address,
            'city' => $this->city,
            'country' => $this->country,
            'latitude' => $this->latitude,
            'longitude' => $this->longitude,

            // Identidad visual y configuración.
            'logo_url' => $this->logo_url,
            'background_url' => $this->background_url,
            'stamp_icon_url' => $this->stamp_icon_url,
            'card_settings' => $this->defaultCardSettings(),
            'qr_settings' => $this->qr_settings ?? [],
            'menu_design' => $this->menu_design ?? [],

            // Estado y moderación.
            'is_active' => (bool) $this->is_active,
            'is_deleted' => $this->trashed(),
            'deleted_at' => $this->deleted_at?->toIso8601String(),

            // Propietario y categorías globales.
            'owner' => new UserResource($this->whenLoaded('owner')),
            'owner_id' => $this->user_id,
            'categories' => BusinessCategoryResource::collection($this->whenLoaded('categories')),

            // Totales (sólo cuando el controlador usa withCount()).
            'loyalty_cards_count' => $this->whenCounted('loyaltyCards'),
            'customers_count' => $this->whenCounted('customerCards'),
            'stamps_count' => $this->whenCounted('stamps'),
            'promotions_count' => $this->whenCounted('promotions'),
            'products_count' => $this->whenCounted('products'),
            'rewards_count' => $this->whenCounted('rewards'),

            // Relaciones completas (sólo en el detalle).
            'loyalty_cards' => LoyaltyCardResource::collection($this->whenLoaded('loyaltyCards')),
            'promotions' => PromotionResource::collection($this->whenLoaded('promotions')),
            'rewards' => RewardResource::collection($this->whenLoaded('rewards')),
            'products' => ProductResource::collection($this->whenLoaded('products')),

            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
