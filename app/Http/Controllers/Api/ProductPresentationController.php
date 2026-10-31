<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ProductPresentation\StoreProductPresentationRequest;
use App\Http\Requests\ProductPresentation\UpdateProductPresentationRequest;
use App\Http\Resources\ProductPresentationResource;
use App\Models\Product;
use App\Models\ProductPresentation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ProductPresentationController extends Controller
{
    /** GET /api/business/menu/products/{product}/presentations */
    public function index(Request $request, Product $product): AnonymousResourceCollection
    {
        $business = $this->businessOrFail($request);
        $this->ensureProductBelongsToBusiness($product, (string) $business->getKey());

        $filters = $request->validate(['is_active' => ['nullable', 'boolean']]);

        $presentations = $product->presentations()
            ->when(isset($filters['is_active']), fn ($query) => $query->where('is_active', (bool) $filters['is_active']))
            ->with('product')
            ->get();

        return ProductPresentationResource::collection($presentations);
    }

    /** POST /api/business/menu/products/{product}/presentations */
    public function store(StoreProductPresentationRequest $request, Product $product): JsonResponse
    {
        $business = $this->businessOrFail($request);
        $this->ensureProductBelongsToBusiness($product, (string) $business->getKey());

        $presentation = $product->presentations()->create($request->validated());

        return (new ProductPresentationResource($presentation->load('product')))->response()->setStatusCode(201);
    }

    /** GET /api/business/menu/products/{product}/presentations/{presentation} */
    public function show(Request $request, Product $product, ProductPresentation $presentation): ProductPresentationResource
    {
        $this->ensurePresentationBelongsToBusiness($request, $product, $presentation);

        return new ProductPresentationResource($presentation->load('product'));
    }

    /** PUT/PATCH /api/business/menu/products/{product}/presentations/{presentation} */
    public function update(
        UpdateProductPresentationRequest $request,
        Product $product,
        ProductPresentation $presentation,
    ): ProductPresentationResource {
        $this->ensurePresentationBelongsToBusiness($request, $product, $presentation);
        $presentation->fill($request->validated())->save();

        return new ProductPresentationResource($presentation->load('product'));
    }

    /** DELETE /api/business/menu/products/{product}/presentations/{presentation} */
    public function destroy(Request $request, Product $product, ProductPresentation $presentation): JsonResponse
    {
        $this->ensurePresentationBelongsToBusiness($request, $product, $presentation);
        $presentation->delete();

        return response()->json(['message' => 'Presentación eliminada.']);
    }

    private function ensurePresentationBelongsToBusiness(
        Request $request,
        Product $product,
        ProductPresentation $presentation,
    ): void {
        $business = $this->businessOrFail($request);
        $this->ensureProductBelongsToBusiness($product, (string) $business->getKey());
        abort_unless((string) $presentation->product_id === (string) $product->getKey(), 404, 'La presentación no existe.');
    }

    private function ensureProductBelongsToBusiness(Product $product, string $businessId): void
    {
        abort_unless((string) $product->business_id === $businessId, 404, 'El producto no existe.');
    }
}
