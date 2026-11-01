<?php

namespace App\Http\Controllers\Api\Public;

use App\Http\Controllers\Controller;
use App\Http\Resources\BusinessMenuCategoryResource;
use App\Models\Business;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class MenuController extends Controller
{
    /** GET /api/public/business/{slug}/menu */
    public function show(string $slug): AnonymousResourceCollection
    {
        $business = Business::query()
            ->where('slug', $slug)
            ->where('is_active', true)
            ->firstOrFail();

        $categories = $business->categories()
            ->with('category')
            ->orderBy('sort_order')
            ->get();

        $products = $business->products()
            ->whereIn('category_id', $categories->pluck('category_id'))
            ->where('is_available', true)
            ->with([
                'category',
                'images',
                'presentations' => fn ($presentations) => $presentations
                    ->where('is_active', true)
                    ->orderBy('sort_order')
                    ->orderBy('name'),
                'presentations.product',
            ])
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();

        foreach ($categories as $category) {
            $category->setRelation(
                'products',
                $products->where('category_id', $category->category_id)->values(),
            );
        }

        return BusinessMenuCategoryResource::collection($categories);
    }
}
