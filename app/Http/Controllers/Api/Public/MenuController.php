<?php

namespace App\Http\Controllers\Api\Public;

use App\Http\Controllers\Controller;
use App\Http\Resources\MenuCategoryResource;
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

        $categories = $business->menuCategories()
            ->where('is_active', true)
            ->with([
                'products' => fn ($query) => $query
                    ->where('business_id', $business->getKey())
                    ->where('is_available', true)
                    ->with([
                        'images',
                        'presentations' => fn ($presentations) => $presentations
                            ->where('is_active', true)
                            ->orderBy('sort_order')
                            ->orderBy('name'),
                        'presentations.product',
                    ])
                    ->orderBy('sort_order')
                    ->orderBy('name'),
            ])
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();

        return MenuCategoryResource::collection($categories);
    }
}
