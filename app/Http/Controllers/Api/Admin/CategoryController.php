<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Category\StoreCategoryRequest;
use App\Http\Requests\Category\UpdateCategoryRequest;
use App\Http\Resources\Category as CategoryResource;
use App\Models\Category;
use App\Services\AssetService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class CategoryController extends Controller
{
    public function __construct(private readonly AssetService $assets)
    {
    }

    /** GET /api/admin/categories */
    public function index(Request $request): AnonymousResourceCollection
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:255'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:'.config('punto_plus.per_page.max')],
        ]);

        $categories = Category::query()
            ->when(filled($filters['search'] ?? null), fn ($query) => $query->where('name', 'like', '%'.$filters['search'].'%'))
            ->orderBy('name')
            ->paginate($this->perPage($request))
            ->withQueryString();

        return CategoryResource::collection($categories);
    }

    /** POST /api/admin/categories */
    public function store(StoreCategoryRequest $request): JsonResponse
    {
        $data = $request->validated();
        $iconBase64 = $data['icon'] ?? null;
        unset($data['icon']);

        $category = Category::create($data);

        if ($iconBase64 !== null) {
            $category->icon = $this->assets->storeCategoryIconBase64($iconBase64, (string) $category->getKey());
            $category->save();
        }

        return (new CategoryResource($category))->response()->setStatusCode(201);
    }

    /** GET /api/admin/categories/{category} */
    public function show(Category $category): CategoryResource
    {
        return new CategoryResource($category);
    }

    /** PUT/PATCH /api/admin/categories/{category} */
    public function update(UpdateCategoryRequest $request, Category $category): CategoryResource
    {
        $data = $request->validated();
        $hasIcon = array_key_exists('icon', $data);
        $iconBase64 = $data['icon'] ?? null;
        unset($data['icon']);

        $category->fill($data);

        if ($hasIcon && $iconBase64 !== null) {
            $category->icon = $this->assets->replaceCategoryIconBase64(
                $category->icon,
                $iconBase64,
                (string) $category->getKey(),
            );
        } elseif ($hasIcon) {
            $this->assets->deleteCategoryIcon($category->icon);
            $category->icon = null;
        }

        $category->save();

        return new CategoryResource($category);
    }

    /** DELETE /api/admin/categories/{category} */
    public function destroy(Category $category): JsonResponse
    {
        $this->assets->deleteCategoryIcon($category->icon);
        $category->delete();

        return response()->json(['message' => 'Categoría eliminada.']);
    }
}
