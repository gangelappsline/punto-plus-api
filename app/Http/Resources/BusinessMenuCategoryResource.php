<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

/** @mixin \App\Models\BusinessCategory */
class BusinessMenuCategoryResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $category = $this->category;

        return [
            'id' => $this->id,
            'business_id' => $this->business_id,
            'category_id' => $this->category_id,
            // Alias retained for clients that used the previous public-menu contract.
            'source_category_id' => $this->category_id,
            'name' => $category?->name,
            'description' => null,
            'icon' => $category?->icon ? Storage::disk('public')->url($category->icon) : '',
            'sort_order' => $this->sort_order,
            'is_active' => true,
            'products' => ProductResource::collection($this->whenLoaded('products')),
        ];
    }
}
