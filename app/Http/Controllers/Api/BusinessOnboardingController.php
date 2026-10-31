<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Business\SaveBusinessOnboardingStepRequest;
use App\Http\Resources\BusinessResource;
use App\Models\Business;
use App\Models\Category;
use App\Models\MenuCategory;
use App\Models\Product;
use App\Services\AssetService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

class BusinessOnboardingController extends Controller
{
    public function __construct(private readonly AssetService $assets)
    {
    }

    /** PUT /api/business/onboarding */
    public function saveStep(SaveBusinessOnboardingStepRequest $request): JsonResponse
    {
        $business = $this->businessOrFail($request);
        $data = $request->validated();
        $step = $data['step'];
        $storedPaths = [];
        $pathsToDelete = [];

        try {
            DB::transaction(function () use ($business, $data, $step, &$storedPaths, &$pathsToDelete): void {
                switch ($step) {
                    case 'information':
                        $this->saveInformation($business, $data['information'], $storedPaths, $pathsToDelete);
                        break;
                    case 'categories':
                        $this->saveCategories($business, $data['categories']['category_ids']);
                        break;
                    case 'products':
                        $this->saveProducts($business, $data['products']['products'], $storedPaths, $pathsToDelete);
                        break;
                    case 'design':
                        $this->saveDesign($business, $data['design']);
                        break;
                }
            });
        } catch (Throwable $exception) {
            foreach ($storedPaths as $path) {
                $this->assets->deletePublicAsset($path);
            }

            throw $exception;
        }

        foreach ($pathsToDelete as $path) {
            $this->assets->deletePublicAsset($path);
        }

        return response()->json([
            'message' => 'Paso guardado correctamente.',
            'data' => [
                'step' => $step,
                'business' => new BusinessResource($business->refresh()->load('categories.category')),
            ],
        ]);
    }

    /**
     * @param array<string, mixed> $data
     * @param list<string> $storedPaths
     * @param list<string> $pathsToDelete
     */
    private function saveInformation(Business $business, array $data, array &$storedPaths, array &$pathsToDelete): void
    {
        $attributes = [];
        foreach (['name', 'description', 'phone', 'address'] as $field) {
            if (array_key_exists($field, $data)) {
                $attributes[$field] = $data[$field];
            }
        }

        if (array_key_exists('phone_numbers', $data)) {
            $attributes['phone_numbers'] = array_values($data['phone_numbers'] ?? []);
        }

        if (array_key_exists('welcome_message', $data)) {
            $business->card_settings = array_merge(
                $business->card_settings ?? [],
                ['welcome_message' => $data['welcome_message']],
            );
        }

        foreach (['logo_url' => 'logo_path', 'background_url' => 'background_path'] as $input => $column) {
            if (! array_key_exists($input, $data)) {
                continue;
            }

            $currentPath = $business->{$column};
            if ($currentPath !== null) {
                $pathsToDelete[] = $currentPath;
            }

            if ($data[$input] === null) {
                $attributes[$column] = null;
                continue;
            }

            $path = $this->assets->storePublicBase64Image(
                $data[$input],
                $this->assets->businessDirectory((string) $business->getKey()),
            );
            $storedPaths[] = $path;
            $attributes[$column] = $path;
        }

        $business->fill($attributes);
        $business->save();
    }

    /** @param list<int> $categoryIds */
    private function saveCategories(Business $business, array $categoryIds): void
    {
        $categoryIds = array_values(array_map('intval', $categoryIds));
        $business->categories()->delete();

        $menuCategories = $business->menuCategories()->whereNotNull('source_category_id');
        if ($categoryIds !== []) {
            $menuCategories->whereNotIn('source_category_id', $categoryIds);
        }
        $menuCategories->update(['is_active' => false]);

        foreach ($categoryIds as $position => $categoryId) {
            $category = Category::query()->findOrFail($categoryId);
            $business->categories()->create([
                'category_id' => $categoryId,
                'sort_order' => $position,
            ]);

            $menuCategory = $business->menuCategories()
                ->where('source_category_id', $categoryId)
                ->first();

            if ($menuCategory === null) {
                $menuCategory = $business->menuCategories()
                    ->whereNull('source_category_id')
                    ->where('name', $category->name)
                    ->first();
            }

            if ($menuCategory === null) {
                $menuCategory = new MenuCategory;
                $menuCategory->business_id = $business->getKey();
            }

            $menuCategory->fill([
                'source_category_id' => $categoryId,
                'name' => $category->name,
                'sort_order' => $position,
                'is_active' => true,
            ])->save();
        }
    }

    /**
     * @param list<array<string, mixed>> $products
     * @param list<string> $storedPaths
     * @param list<string> $pathsToDelete
     */
    private function saveProducts(Business $business, array $products, array &$storedPaths, array &$pathsToDelete): void
    {
        $selectedCategoryIds = $business->categories()->pluck('category_id')->map(fn ($id): int => (int) $id)->all();

        foreach ($products as $index => $data) {
            if (! in_array((int) $data['category_id'], $selectedCategoryIds, true)) {
                throw ValidationException::withMessages([
                    "products.products.{$index}.category_id" => 'La categoría del producto debe estar seleccionada para este negocio.',
                ]);
            }

            $menuCategoryId = $business->menuCategories()
                ->where('source_category_id', $data['category_id'])
                ->value('id');
            if ($menuCategoryId === null) {
                throw ValidationException::withMessages([
                    "products.products.{$index}.category_id" => 'Guarda primero las categorías del negocio antes de asignar productos.',
                ]);
            }

            $id = $data['id'];
            $product = $business->products()->whereKey($id)->first();
            if ($product === null && Product::query()->whereKey($id)->exists()) {
                throw ValidationException::withMessages([
                    "products.products.{$index}.id" => 'El identificador del producto ya está asociado a otro negocio.',
                ]);
            }

            $oldPaths = $product?->images()->pluck('path')->all() ?? [];
            foreach ($oldPaths as $oldPath) {
                $pathsToDelete[] = $oldPath;
            }

            $imagePath = $this->assets->storePublicBase64Image(
                $data['image_url'],
                'businesses/'.$business->getKey().'/products/'.$id,
            );
            $storedPaths[] = $imagePath;

            $attributes = [
                'category_id' => $data['category_id'],
                'menu_category_id' => $menuCategoryId,
                'name' => $data['name'],
                'description' => $data['description'] ?? null,
                'price' => $data['price'] ?? null,
                'sort_order' => $data['position'] ?? $index,
                'is_available' => $data['available'] ?? true,
            ];

            if ($product === null) {
                $product = $business->products()->make($attributes);
                $product->id = $id;
                $product->save();
            } else {
                $product->fill($attributes)->save();
            }

            $product->images()->delete();
            $product->images()->create(['path' => $imagePath, 'sort_order' => 0]);
        }
    }

    /** @param array<string, mixed> $data */
    private function saveDesign(Business $business, array $data): void
    {
        $business->menu_design = array_merge($business->menu_design ?? [], $data);
        $business->save();
    }
}
