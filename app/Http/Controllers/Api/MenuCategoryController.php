<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\MenuCategory\StoreMenuCategoryRequest;
use App\Http\Requests\MenuCategory\UpdateMenuCategoryRequest;
use App\Http\Resources\MenuCategoryResource;
use App\Models\MenuCategory;
use App\Services\AssetService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class MenuCategoryController extends Controller
{
    public function __construct(private readonly AssetService $assets)
    {
    }

    /** GET /api/business/menu/categories */
    public function index(Request $request): AnonymousResourceCollection
    {
        $business = $this->businessOrFail($request);
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:150'],
            'is_active' => ['nullable', 'boolean'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:'.config('punto_plus.per_page.max')],
        ]);

        $categories = $business->menuCategories()
            ->when(filled($filters['search'] ?? null), fn ($query) => $query->where('name', 'like', '%'.$filters['search'].'%'))
            ->when(isset($filters['is_active']), fn ($query) => $query->where('is_active', (bool) $filters['is_active']))
            ->withCount('products')
            ->orderBy('sort_order')
            ->orderBy('name')
            ->paginate($this->perPage($request))
            ->withQueryString();

        return MenuCategoryResource::collection($categories);
    }

    /** POST /api/business/menu/categories */
    public function store(StoreMenuCategoryRequest $request): JsonResponse
    {
        $business = $this->businessOrFail($request);
        $category = $business->menuCategories()->create($request->validated());

        return (new MenuCategoryResource($category))->response()->setStatusCode(201);
    }

    /** GET /api/business/menu/categories/{menuCategory} */
    public function show(Request $request, MenuCategory $menuCategory): MenuCategoryResource
    {
        $this->ensureOwnedCategory($request, $menuCategory);

        return new MenuCategoryResource($menuCategory->loadCount('products'));
    }

    /** PUT/PATCH /api/business/menu/categories/{menuCategory} */
    public function update(UpdateMenuCategoryRequest $request, MenuCategory $menuCategory): MenuCategoryResource
    {
        $this->ensureOwnedCategory($request, $menuCategory);
        $menuCategory->fill($request->validated())->save();

        return new MenuCategoryResource($menuCategory->loadCount('products'));
    }

    /** DELETE /api/business/menu/categories/{menuCategory} */
    public function destroy(Request $request, MenuCategory $menuCategory): JsonResponse
    {
        $this->ensureOwnedCategory($request, $menuCategory);
        $menuCategory->load('products.images');

        foreach ($menuCategory->products as $product) {
            foreach ($product->images as $image) {
                $this->assets->deletePublicAsset($image->path);
            }
        }

        $menuCategory->delete();

        return response()->json(['message' => 'Categoría del menú eliminada.']);
    }

    private function ensureOwnedCategory(Request $request, MenuCategory $category): void
    {
        $business = $this->businessOrFail($request);
        abort_unless((string) $category->business_id === (string) $business->getKey(), 404, 'La categoría del menú no existe.');
    }
}
