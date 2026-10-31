<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\Product */
class ProductResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'business_id' => $this->business_id,
            'menu_category_id' => $this->menu_category_id,
            'category_id' => $this->category_id,
            'menu_category' => new MenuCategoryResource($this->whenLoaded('menuCategory')),
            'name' => $this->name,
            'description' => $this->description,
            'price' => $this->price,
            'sort_order' => $this->sort_order,
            'is_available' => (bool) $this->is_available,
            'images' => ProductImageResource::collection($this->whenLoaded('images')),
            'presentations' => ProductPresentationResource::collection($this->whenLoaded('presentations')),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
