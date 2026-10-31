<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Product\StoreProductRequest;
use App\Http\Requests\Product\UpdateProductRequest;
use App\Http\Resources\ProductResource;
use App\Models\MenuCategory;
use App\Models\Product;
use App\Services\AssetService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

class ProductController extends Controller
{
    public function __construct(private readonly AssetService $assets)
    {
    }

    /** GET /api/business/menu/categories/{menuCategory}/products */
    public function indexForCategory(Request $request, MenuCategory $menuCategory): AnonymousResourceCollection
    {
        $business = $this->businessOrFail($request);
        $this->ensureCategoryBelongsToBusiness($menuCategory, (string) $business->getKey());

        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:180'],
            'is_available' => ['nullable', 'boolean'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:'.config('punto_plus.per_page.max')],
        ]);

        $products = $menuCategory->products()
            ->where('business_id', $business->getKey())
            ->when(filled($filters['search'] ?? null), fn ($query) => $query->where('name', 'like', '%'.$filters['search'].'%'))
            ->when(isset($filters['is_available']), fn ($query) => $query->where('is_available', (bool) $filters['is_available']))
            ->with(['images', 'presentations.product', 'menuCategory'])
            ->orderBy('sort_order')
            ->orderBy('name')
            ->paginate($this->perPage($request))
            ->withQueryString();

        return ProductResource::collection($products);
    }

    /** POST /api/business/menu/categories/{menuCategory}/products */
    public function storeForCategory(StoreProductRequest $request, MenuCategory $menuCategory): JsonResponse
    {
        $business = $this->businessOrFail($request);
        $this->ensureCategoryBelongsToBusiness($menuCategory, (string) $business->getKey());

        $data = $request->validated();
        $images = $data['images'];
        unset($data['images']);

        $storedPaths = [];

        try {
            $product = DB::transaction(function () use ($data, $images, $business, $menuCategory, &$storedPaths): Product {
                $product = $menuCategory->products()->create([
                    ...$data,
                    'business_id' => $business->getKey(),
                ]);

                foreach ($images as $sortOrder => $imageData) {
                    $path = $this->assets->storePublicBase64Image(
                        $imageData,
                        'businesses/'.$business->getKey().'/products/'.$product->getKey(),
                    );
                    $storedPaths[] = $path;
                    $product->images()->create(['path' => $path, 'sort_order' => $sortOrder]);
                }

                return $product;
            });
        } catch (Throwable $exception) {
            foreach ($storedPaths as $path) {
                $this->assets->deletePublicAsset($path);
            }

            throw $exception;
        }

        return (new ProductResource($this->loadProductRelations($product)))->response()->setStatusCode(201);
    }

    /** GET /api/business/menu/products/{product} */
    public function show(Request $request, Product $product): ProductResource
    {
        $business = $this->businessOrFail($request);
        $this->ensureProductBelongsToBusiness($product, (string) $business->getKey());

        return new ProductResource($this->loadProductRelations($product));
    }

    /** PUT/PATCH /api/business/menu/products/{product} */
    public function update(UpdateProductRequest $request, Product $product): ProductResource
    {
        $business = $this->businessOrFail($request);
        $this->ensureProductBelongsToBusiness($product, (string) $business->getKey());

        $data = $request->validated();
        $hasImages = array_key_exists('images', $data);
        $newImages = $data['images'] ?? [];
        unset($data['images']);

        if (array_key_exists('menu_category_id', $data)) {
            $category = $business->menuCategories()->find($data['menu_category_id']);

            if ($category === null) {
                throw ValidationException::withMessages([
                    'menu_category_id' => 'La categoría seleccionada no pertenece a tu negocio.',
                ]);
            }
        }

        $oldPaths = $hasImages ? $product->images()->pluck('path')->all() : [];
        $storedPaths = [];

        try {
            DB::transaction(function () use ($product, $data, $hasImages, $newImages, $business, &$storedPaths): void {
                $product->fill($data)->save();

                if (! $hasImages) {
                    return;
                }

                $product->images()->delete();

                foreach ($newImages as $sortOrder => $imageData) {
                    $path = $this->assets->storePublicBase64Image(
                        $imageData,
                        'businesses/'.$business->getKey().'/products/'.$product->getKey(),
                    );
                    $storedPaths[] = $path;
                    $product->images()->create(['path' => $path, 'sort_order' => $sortOrder]);
                }
            });
        } catch (Throwable $exception) {
            foreach ($storedPaths as $path) {
                $this->assets->deletePublicAsset($path);
            }

            throw $exception;
        }

        foreach ($oldPaths as $path) {
            $this->assets->deletePublicAsset($path);
        }

        return new ProductResource($this->loadProductRelations($product->refresh()));
    }

    /** DELETE /api/business/menu/products/{product} */
    public function destroy(Request $request, Product $product): JsonResponse
    {
        $business = $this->businessOrFail($request);
        $this->ensureProductBelongsToBusiness($product, (string) $business->getKey());

        foreach ($product->images as $image) {
            $this->assets->deletePublicAsset($image->path);
        }

        $product->delete();

        return response()->json(['message' => 'Producto eliminado.']);
    }

    private function loadProductRelations(Product $product): Product
    {
        return $product->load([
            'menuCategory',
            'images',
            'presentations' => fn ($query) => $query->orderBy('sort_order')->orderBy('name'),
            'presentations.product',
        ]);
    }

    private function ensureCategoryBelongsToBusiness(MenuCategory $category, string $businessId): void
    {
        abort_unless((string) $category->business_id === $businessId, 404, 'La categoría del menú no existe.');
    }

    private function ensureProductBelongsToBusiness(Product $product, string $businessId): void
    {
        abort_unless((string) $product->business_id === $businessId, 404, 'El producto no existe.');
    }
}
