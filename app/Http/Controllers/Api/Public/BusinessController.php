<?php

namespace App\Http\Controllers\Api\Public;

use App\Http\Controllers\Controller;
use App\Http\Requests\Business\SaveBusinessOnboardingStepRequest;
use App\Http\Resources\BusinessResource;
use App\Models\Business;
use App\Models\Product;
use App\Services\AssetService;
use App\Support\ImageInput;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

class BusinessController extends Controller
{
    public function __construct(private readonly AssetService $assets)
    {
    }

    public function show(string $slug): JsonResponse
    {
        $business = Business::whereSlug($slug)->firstOrFail();

        return response()->json(new BusinessResource($business));
    }

    public function card(Request $request): JsonResponse
    {
        $business = Business::where('user_id', $request->user()->id)->firstOrFail();
        $business->load('products', 'owner', 'loyaltyCards', 'categories', 'promotions');

        return response()->json(new BusinessResource($business));
    }

    /** Guarda el paso indicado por `step` en el perfil del negocio autenticado. */
    public function updateCard(SaveBusinessOnboardingStepRequest $request): JsonResponse
    {
        $business = Business::where('user_id', $request->user()->id)->firstOrFail();
        $data = $request->validated();
        $step = $data['step'];
        $section = $data['data'];
        $storedPaths = [];
        $pathsToDelete = [];

        try {
            DB::transaction(function () use ($business, $section, $step, &$storedPaths, &$pathsToDelete): void {
                switch ($step) {
                    case 'information':
                        $this->saveInformation($business, $section, $storedPaths, $pathsToDelete);
                        break;
                    case 'categories':
                        $this->saveCategories($business, $section['category_ids']);
                        break;
                    case 'products':
                        $this->saveProducts($business, $section['products'], $storedPaths, $pathsToDelete);
                        break;
                    case 'design':
                        $this->saveDesign($business, $section);
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

        $business->refresh()->load([
            'products.images',
            'products.category',
            'owner',
            'loyaltyCards',
            'categories.category',
            'promotions',
        ]);

        return response()->json(new BusinessResource($business));
    }

    /**
     * @param array<string, mixed> $data
     * @param list<string> $storedPaths
     * @param list<string> $pathsToDelete
     */
    private function saveInformation(Business $business, array $data, array &$storedPaths, array &$pathsToDelete): void
    {
        $previousName = $business->name;
        $attributes = [];
        foreach (['name', 'description', 'address'] as $field) {
            if (array_key_exists($field, $data)) {
                $attributes[$field] = $data[$field];
            }
        }

        $phoneNumbers = array_key_exists('phone_numbers', $data)
            ? $this->normalizePhoneNumbers($data['phone_numbers'])
            : ($business->phone_numbers ?? []);
        if (array_key_exists('phone', $data)) {
            $submittedPhoneNumbers = $this->normalizePhoneNumbers($data['phone']);
            $attributes['phone'] = array_shift($submittedPhoneNumbers);
            if ($submittedPhoneNumbers !== [] && ! array_key_exists('phone_numbers', $data)) {
                $phoneNumbers = [];
            }
            $phoneNumbers = array_values(array_unique([...$phoneNumbers, ...$submittedPhoneNumbers]));
        }
        if (array_key_exists('phone_numbers', $data) || (array_key_exists('phone', $data) && $phoneNumbers !== [])) {
            $attributes['phone_numbers'] = $phoneNumbers;
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
            if ($data[$input] === null) {
                if ($currentPath !== null) {
                    $pathsToDelete[] = $currentPath;
                }
                $attributes[$column] = null;
                continue;
            }

            $imageInput = ImageInput::normalizeMarkdownLink($data[$input]);
            if (ImageInput::isHttpUrl($imageInput)) {
                if (! $this->urlMatchesStoredAsset($imageInput, $currentPath)) {
                    if ($currentPath !== null) {
                        $pathsToDelete[] = $currentPath;
                    }
                    $attributes[$column] = $imageInput;
                }
                continue;
            }

            if ($currentPath !== null) {
                $pathsToDelete[] = $currentPath;
            }

            $path = $this->assets->storePublicBase64Image(
                $imageInput,
                $this->assets->businessDirectory((string) $business->getKey()),
            );
            $storedPaths[] = $path;
            $attributes[$column] = $path;
        }

        $business->fill($attributes);
        if (blank($business->slug) || $business->name !== $previousName) {
            $business->slug = Str::slug($business->name);
        }
        $business->save();
    }

    /** @param list<int> $categoryIds */
    private function saveCategories(Business $business, array $categoryIds): void
    {
        $business->categories()->delete();

        foreach (array_values(array_map('intval', $categoryIds)) as $position => $categoryId) {
            $business->categories()->create([
                'category_id' => $categoryId,
                'sort_order' => $position,
            ]);
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
                    "data.products.{$index}.category_id" => 'La categoría del producto debe estar seleccionada para este negocio.',
                ]);
            }

            $id = $data['id'];
            $product = $business->products()->whereKey($id)->first();
            if ($product === null && Product::query()->whereKey($id)->exists()) {
                throw ValidationException::withMessages([
                    "data.products.{$index}.id" => 'El identificador del producto ya está asociado a otro negocio.',
                ]);
            }

            $oldPaths = $product?->images()->pluck('path')->all() ?? [];
            $submittedImage = ImageInput::normalizeMarkdownLink($data['image_url']);
            $isExistingImageUrl = ImageInput::isHttpUrl($submittedImage);

            if ($isExistingImageUrl) {
                $storedImagePath = $this->pathFromProductImageUrl($submittedImage, $business, $id);
                if ($product === null || $storedImagePath === null || ! in_array($storedImagePath, $oldPaths, true)) {
                    throw ValidationException::withMessages([
                        "data.products.{$index}.image_url" => 'La URL debe corresponder a una imagen ya guardada para este producto.',
                    ]);
                }

                $imagePath = null;
            } else {
                foreach ($oldPaths as $oldPath) {
                    $pathsToDelete[] = $oldPath;
                }

                $imagePath = $this->assets->storePublicBase64Image(
                    $submittedImage,
                    'businesses/'.$business->getKey().'/products/'.$id,
                );
                $storedPaths[] = $imagePath;
            }

            $attributes = [
                'category_id' => $data['category_id'],
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

            if ($imagePath !== null) {
                $product->images()->delete();
                $product->images()->create(['path' => $imagePath, 'sort_order' => 0]);
            }
        }
    }

    private function urlMatchesStoredAsset(string $url, ?string $storedPath): bool
    {
        if ($storedPath === null) {
            return false;
        }

        if ($url === $storedPath) {
            return true;
        }

        $storedUrl = Storage::disk('public')->url($storedPath);
        if ($url === $storedUrl) {
            return true;
        }

        $urlPath = parse_url($url, PHP_URL_PATH);
        $storedUrlPath = parse_url($storedUrl, PHP_URL_PATH);

        return is_string($urlPath) && is_string($storedUrlPath) && $urlPath === $storedUrlPath;
    }

    /** @return list<string> */
    private function normalizePhoneNumbers(array|string|null $value): array
    {
        $items = is_array($value) ? $value : [$value];
        $numbers = [];

        foreach ($items as $item) {
            if (! is_string($item)) {
                continue;
            }

            foreach (explode(',', $item) as $number) {
                $number = trim($number);
                if ($number !== '') {
                    $numbers[] = $number;
                }
            }
        }

        return array_values(array_unique($numbers));
    }

    private function pathFromProductImageUrl(string $url, Business $business, string $productId): ?string
    {
        $urlPath = parse_url($url, PHP_URL_PATH);
        if (! is_string($urlPath) || ! str_starts_with($urlPath, '/storage/')) {
            return null;
        }

        $path = rawurldecode(substr($urlPath, strlen('/storage/')));
        $expectedPrefix = 'businesses/'.$business->getKey().'/products/'.$productId.'/';

        return str_starts_with($path, $expectedPrefix) ? $path : null;
    }

    /** @param array<string, mixed> $data */
    private function saveDesign(Business $business, array $data): void
    {
        $business->menu_design = array_merge($business->menu_design ?? [], $data);
        $business->save();
    }
}
