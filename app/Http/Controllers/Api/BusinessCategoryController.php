<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\BusinessCategory\StoreBusinessCategoryRequest;
use App\Http\Requests\BusinessCategory\UpdateBusinessCategoryRequest;
use App\Http\Resources\BusinessMenuCategoryResource;
use App\Models\BusinessCategory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;

class BusinessCategoryController extends Controller
{
    /** GET /api/business/menu/categories */
    public function index(Request $request): AnonymousResourceCollection
    {
        $business = $this->businessOrFail($request);
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:150'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:'.config('punto_plus.per_page.max')],
        ]);

        $categories = $business->categories()
            ->with('category')
            ->when(filled($filters['search'] ?? null), fn ($query) => $query->whereHas(
                'category',
                fn ($categoryQuery) => $categoryQuery->where('name', 'like', '%'.$filters['search'].'%'),
            ))
            ->orderBy('sort_order')
            ->orderBy('category_id')
            ->paginate($this->perPage($request))
            ->withQueryString();

        return BusinessMenuCategoryResource::collection($categories);
    }

    /** POST /api/business/menu/categories */
    public function store(StoreBusinessCategoryRequest $request): JsonResponse
    {
        $business = $this->businessOrFail($request);
        $category = $business->categories()->create($request->validated());

        return (new BusinessMenuCategoryResource($category->load('category')))
            ->response()
            ->setStatusCode(201);
    }

    /** GET /api/business/menu/categories/{businessCategory} */
    public function show(Request $request, BusinessCategory $businessCategory): BusinessMenuCategoryResource
    {
        $this->ensureOwnedCategory($request, $businessCategory);

        return new BusinessMenuCategoryResource($businessCategory->load('category'));
    }

    /** PUT/PATCH /api/business/menu/categories/{businessCategory} */
    public function update(UpdateBusinessCategoryRequest $request, BusinessCategory $businessCategory): BusinessMenuCategoryResource
    {
        $this->ensureOwnedCategory($request, $businessCategory);
        $data = $request->validated();
        $business = $this->businessOrFail($request);
        $previousCategoryId = $businessCategory->category_id;

        DB::transaction(function () use ($business, $businessCategory, $data, $previousCategoryId): void {
            $businessCategory->fill($data)->save();
            if (isset($data['category_id']) && (int) $data['category_id'] !== (int) $previousCategoryId) {
                $business->products()
                    ->where('category_id', $previousCategoryId)
                    ->update(['category_id' => $data['category_id']]);
            }
        });

        return new BusinessMenuCategoryResource($businessCategory->refresh()->load('category'));
    }

    /** DELETE /api/business/menu/categories/{businessCategory} */
    public function destroy(Request $request, BusinessCategory $businessCategory): JsonResponse
    {
        $this->ensureOwnedCategory($request, $businessCategory);
        $businessCategory->delete();

        return response()->json(['message' => 'Categoría eliminada.']);
    }

    private function ensureOwnedCategory(Request $request, BusinessCategory $category): void
    {
        $business = $this->businessOrFail($request);
        abort_unless((string) $category->business_id === (string) $business->getKey(), 404, 'La categoría no existe.');
    }
}
