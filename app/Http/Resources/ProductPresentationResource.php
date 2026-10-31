<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\ProductPresentation */
class ProductPresentationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $inheritedPrice = $this->price === null && $this->relationLoaded('product')
            ? $this->product?->price
            : null;

        return [
            'id' => $this->id,
            'product_id' => $this->product_id,
            'name' => $this->name,
            'price' => $this->price,
            'effective_price' => $this->price ?? $inheritedPrice,
            'inherits_product_price' => $this->price === null,
            'sort_order' => $this->sort_order,
            'is_active' => (bool) $this->is_active,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
