<?php

namespace App\Http\Controllers\Api\Admin;

use App\Enums\CardStatus;
use App\Enums\RedemptionStatus;
use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreBusinessRequest;
use App\Http\Requests\Admin\UpdateBusinessRequest;
use App\Http\Resources\Admin\BusinessResource as AdminBusinessResource;
use App\Models\Business;
use App\Models\CustomerCard;
use App\Models\LoyaltyCard;
use App\Models\Product;
use App\Models\Promotion;
use App\Models\Redemption;
use App\Models\Reward;
use App\Models\Stamp;
use App\Models\User;
use App\Services\AssetService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Throwable;

/**
 * CRUD de negocios para el panel de administración de Punto Plus.
 *
 * Todas las rutas viven bajo `/api/admin/business` y están protegidas por
 * `auth:api` + `role:admin` (ver routes/api.php). El listado devuelve, junto a
 * la página de resultados, las estadísticas globales de la plataforma.
 */
class BusinessController extends Controller
{
    /**
     * Entradas de branding aceptadas => columna en `businesses`.
     *
     * Se envían como imagen base64 o data URI (JPEG/PNG/WEBP) y se guardan en
     * el disco público dentro de la carpeta del negocio.
     */
    private const ASSET_FIELDS = [
        'logo_url' => 'logo_path',
        'background_url' => 'background_path',
        'stamp_icon_url' => 'stamp_icon_path',
    ];

    public function __construct(private readonly AssetService $assets)
    {
    }

    /**
     * GET /api/admin/business
     *
     * Estadísticas globales + listado paginado de negocios.
     *
     * Filtros: `search`, `is_active`, `category` (texto libre del negocio),
     * `category_id` (categoría global asociada), `city`, `trashed`
     * (`with`|`only`), `sort`, `direction`, `per_page`.
     */
    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:120'],
            'is_active' => ['nullable', 'boolean'],
            'category' => ['nullable', 'string', 'max:80'],
            'category_id' => ['nullable', 'integer', 'exists:categories,id'],
            'city' => ['nullable', 'string', 'max:100'],
            'trashed' => ['nullable', 'string', Rule::in(['with', 'only'])],
            'sort' => [
                'nullable',
                'string',
                Rule::in([
                    'name', 'created_at', 'updated_at', 'is_active',
                    'loyalty_cards_count', 'customer_cards_count', 'customers_count',
                    'stamps_count', 'promotions_count',
                ]),
            ],
            'direction' => ['nullable', 'string', Rule::in(['asc', 'desc'])],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:'.config('punto_plus.per_page.max')],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);

        $businesses = $this->filteredQuery($filters)
            ->paginate($this->perPage($request))
            ->withQueryString();

        return response()->json([
            'stats' => $this->platformStats(),
            'businesses' => AdminBusinessResource::collection($businesses)->response()->getData(true),
        ]);
    }

    /**
     * POST /api/admin/business
     *
     * Crea el negocio y, si se envía `owner`, también su usuario propietario.
     */
    public function store(StoreBusinessRequest $request): JsonResponse
    {
        $data = $request->validated();

        $ownerData = Arr::pull($data, 'owner');
        $ownerId = Arr::pull($data, 'user_id');
        $categoryIds = Arr::pull($data, 'category_ids');
        $cardSettings = Arr::pull($data, 'card_settings');
        $images = $this->extractImages($data);

        if (array_key_exists('phone_numbers', $data)) {
            $data['phone_numbers'] = $this->normalizePhoneNumbers($data['phone_numbers']);
        }

        if (array_key_exists('is_active', $data)) {
            $data['is_active'] = filter_var($data['is_active'], FILTER_VALIDATE_BOOLEAN);
        }

        if ($cardSettings !== null) {
            $data['card_settings'] = array_merge(
                config('punto_plus.cards.default_settings'),
                ['required_stamps' => config('punto_plus.cards.default_required_stamps')],
                $cardSettings,
            );
        }

        $slug = Arr::pull($data, 'slug');
        $storedPaths = [];
        $pathsToDelete = [];

        try {
            $business = DB::transaction(function () use ($data, $ownerData, $ownerId, $categoryIds, $images, $slug, &$storedPaths, &$pathsToDelete): Business {
                $owner = $this->resolveOwner($ownerData, $ownerId);
                $business = new Business();

                $business->fill($data);
                $business->user_id = $owner->getKey();
                $business->slug = $this->uniqueSlug($slug ?? $business->name);
                $business->save();

                if ($categoryIds !== null) {
                    $this->syncCategories($business, $categoryIds);
                }

                $business->fill($this->storeImages($business, $images, $storedPaths, $pathsToDelete));
                $business->save();

                return $business;
            });
        } catch (Throwable $exception) {
            $this->deleteAssets($storedPaths);

            throw $exception;
        }

        $this->deleteAssets($pathsToDelete);

        $business->load(['owner', 'categories.category'])->loadCount([
            'loyaltyCards', 'customerCards', 'stamps', 'promotions', 'products', 'rewards',
        ]);

        return (new AdminBusinessResource($business))->response()->setStatusCode(201);
    }

    /**
     * GET /api/admin/business/{business}
     *
     * Detalle del negocio + métricas del negocio (clientes, sellos y canjes).
     */
    public function show(Business $business): JsonResponse
    {
        $business->load([
            'owner',
            'categories.category',
            'loyaltyCards' => fn ($query) => $query->withCount('rewards'),
            'promotions',
            'rewards',
        ])->loadCount([
            'loyaltyCards', 'customerCards', 'stamps', 'promotions', 'products', 'rewards',
        ]);

        return (new AdminBusinessResource($business))
            ->additional(['stats' => $this->businessStats($business)])
            ->response();
    }

    /**
     * PUT/PATCH /api/admin/business/{business}
     */
    public function update(UpdateBusinessRequest $request, Business $business): JsonResponse
    {
        $data = $request->validated();

        $ownerId = Arr::pull($data, 'user_id');
        $categoryIds = Arr::pull($data, 'category_ids');
        $cardSettings = Arr::pull($data, 'card_settings');
        $images = $this->extractImages($data);
        $slugProvided = array_key_exists('slug', $data);
        $slug = Arr::pull($data, 'slug');
        $previousName = $business->name;
        $storedPaths = [];
        $pathsToDelete = [];

        if (array_key_exists('phone_numbers', $data)) {
            $data['phone_numbers'] = $this->normalizePhoneNumbers($data['phone_numbers']);
        }

        if (array_key_exists('is_active', $data)) {
            $data['is_active'] = filter_var($data['is_active'], FILTER_VALIDATE_BOOLEAN);
        }

        if ($cardSettings !== null) {
            $data['card_settings'] = array_merge($business->card_settings ?? [], $cardSettings);
        }

        try {
            DB::transaction(function () use (
                $business,
                $data,
                $ownerId,
                $categoryIds,
                $images,
                $slug,
                $slugProvided,
                $previousName,
                &$storedPaths,
                &$pathsToDelete,
            ): void {
                if ($ownerId !== null) {
                    $business->user_id = (int) $ownerId;
                }

                $business->fill($data);

                if ($slugProvided) {
                    $business->slug = $this->uniqueSlug($slug ?? $business->name, (string) $business->getKey());
                } elseif ($business->name !== $previousName) {
                    $business->slug = $this->uniqueSlug($business->name, (string) $business->getKey());
                }

                $business->fill($this->storeImages($business, $images, $storedPaths, $pathsToDelete));
                $business->save();

                if ($categoryIds !== null) {
                    $this->syncCategories($business, $categoryIds);
                }
            });
        } catch (Throwable $exception) {
            $this->deleteAssets($storedPaths);

            throw $exception;
        }

        $this->deleteAssets($pathsToDelete);

        $business->refresh()->load(['owner', 'categories.category'])->loadCount([
            'loyaltyCards', 'customerCards', 'stamps', 'promotions', 'products', 'rewards',
        ]);

        return (new AdminBusinessResource($business))
            ->additional(['stats' => $this->businessStats($business)])
            ->response();
    }

    /**
     * DELETE /api/admin/business/{business}
     *
     * Borrado lógico: el negocio y sus tarjetas dejan de estar disponibles para
     * los clientes (el alta por QR exige un negocio activo) pero se conserva el
     * histórico de sellos y canjes por si se restaura.
     */
    public function destroy(Business $business): JsonResponse
    {
        $business->delete();

        return response()->json([
            'message' => 'Negocio eliminado.',
            'id' => $business->getKey(),
            'deleted_at' => $business->deleted_at?->toIso8601String(),
        ]);
    }

    /**
     * POST /api/admin/business/{business}/restore
     */
    public function restore(Business $business): JsonResponse
    {
        if ($business->trashed()) {
            $business->restore();
        }

        $business->load(['owner', 'categories.category'])->loadCount([
            'loyaltyCards', 'customerCards', 'stamps', 'promotions', 'products', 'rewards',
        ]);

        return (new AdminBusinessResource($business))
            ->additional([
                'message' => $business->trashed() ? 'El negocio sigue eliminado.' : 'Negocio restaurado.',
            ])
            ->response();
    }

    // -----------------------------------------------------------------
    // Listado
    // -----------------------------------------------------------------

    /**
     * Consulta base del listado con filtros, orden y relaciones necesarias.
     *
     * @param  array<string, mixed>  $filters
     */
    private function filteredQuery(array $filters): Builder
    {
        $query = Business::query()
            ->with(['owner', 'categories.category'])
            ->withCount(['loyaltyCards', 'customerCards', 'stamps', 'promotions']);

        $trashed = $filters['trashed'] ?? null;

        if ($trashed === 'with') {
            $query->withTrashed();
        } elseif ($trashed === 'only') {
            $query->onlyTrashed();
        }

        $query
            ->when(filled($filters['search'] ?? null), function (Builder $query) use ($filters): void {
                $like = '%'.$filters['search'].'%';

                $query->where(function (Builder $query) use ($like): void {
                    $query->where('name', 'like', $like)
                        ->orWhere('slug', 'like', $like)
                        ->orWhere('email', 'like', $like)
                        ->orWhere('phone', 'like', $like)
                        ->orWhere('city', 'like', $like)
                        ->orWhere('category', 'like', $like)
                        ->orWhereHas('owner', function (Builder $owner) use ($like): void {
                            $owner->where('name', 'like', $like)->orWhere('email', 'like', $like);
                        });
                });
            })
            ->when(
                ($filters['is_active'] ?? null) !== null,
                fn (Builder $query): Builder => $query->where('is_active', filter_var($filters['is_active'], FILTER_VALIDATE_BOOLEAN)),
            )
            ->when(filled($filters['category'] ?? null), fn (Builder $query): Builder => $query->where('category', $filters['category']))
            ->when(filled($filters['city'] ?? null), fn (Builder $query): Builder => $query->where('city', $filters['city']))
            ->when(filled($filters['category_id'] ?? null), function (Builder $query) use ($filters): void {
                $query->whereHas('categories', fn (Builder $categories): Builder => $categories->where('category_id', (int) $filters['category_id']));
            });

        // `customers_count` es el nombre que expone el recurso; la columna que
        // genera `withCount('customerCards')` es `customer_cards_count`.
        $sort = match ($filters['sort'] ?? null) {
            'customers_count' => 'customer_cards_count',
            null, '' => 'created_at',
            default => $filters['sort'],
        };

        $direction = $filters['direction'] ?? ($sort === 'name' ? 'asc' : 'desc');

        $query->orderBy($sort, $direction);

        if ($sort !== 'name') {
            $query->orderBy('name');
        }

        return $query;
    }

    // -----------------------------------------------------------------
    // Estadísticas
    // -----------------------------------------------------------------

    /**
     * Métricas globales de la plataforma para el panel de administración.
     *
     * @return array<string, mixed>
     */
    private function platformStats(): array
    {
        $businessesTotal = Business::query()->count();
        $businessesActive = Business::query()->where('is_active', true)->count();
        $loyaltyCardsTotal = LoyaltyCard::query()->count();
        $customersTotal = CustomerCard::query()->count();
        $stampsTotal = Stamp::query()->count();

        $ownersTotal = User::query()->where('role', UserRole::Negocio->value)->count();
        $ownersWithBusiness = User::query()
            ->where('role', UserRole::Negocio->value)
            ->whereHas('business')
            ->count();

        return [
            'businesses' => [
                'total' => $businessesTotal,
                'active' => $businessesActive,
                'inactive' => $businessesTotal - $businessesActive,
                'without_loyalty_cards' => Business::query()->doesntHave('loyaltyCards')->count(),
                'new_last_30_days' => Business::query()->where('created_at', '>=', now()->subDays(30))->count(),
                'new_this_month' => Business::query()->where('created_at', '>=', now()->startOfMonth())->count(),
                'trashed' => Business::onlyTrashed()->count(),
            ],
            'owners' => [
                'total' => $ownersTotal,
                'with_business' => $ownersWithBusiness,
                'without_business' => max(0, $ownersTotal - $ownersWithBusiness),
            ],
            'loyalty_cards' => [
                'total' => $loyaltyCardsTotal,
                'active' => LoyaltyCard::query()->where('is_active', true)->count(),
                'public' => LoyaltyCard::query()->where('is_active', true)->where('is_public', true)->count(),
            ],
            'customers' => [
                'total' => $customersTotal,
                'active' => CustomerCard::query()->where('status', CardStatus::Active->value)->count(),
                'completed' => CustomerCard::query()->where('status', CardStatus::Completed->value)->count(),
                'new_last_30_days' => CustomerCard::query()->where('created_at', '>=', now()->subDays(30))->count(),
            ],
            'stamps' => [
                'total' => $stampsTotal,
                'today' => Stamp::query()->where('stamped_at', '>=', now()->startOfDay())->count(),
                'last_30_days' => Stamp::query()->where('stamped_at', '>=', now()->subDays(30))->count(),
            ],
            'redemptions' => [
                'total' => Redemption::query()->count(),
                'pending' => Redemption::query()->where('status', RedemptionStatus::Pending->value)->count(),
                'completed' => Redemption::query()->where('status', RedemptionStatus::Completed->value)->count(),
                'rejected' => Redemption::query()->where('status', RedemptionStatus::Rejected->value)->count(),
                'cancelled' => Redemption::query()->where('status', RedemptionStatus::Cancelled->value)->count(),
                'last_30_days' => Redemption::query()->where('created_at', '>=', now()->subDays(30))->count(),
            ],
            'promotions' => [
                'total' => Promotion::query()->count(),
                'active' => Promotion::query()->active()->count(),
            ],
            'rewards' => [
                'total' => Reward::query()->count(),
            ],
            'products' => [
                'total' => Product::query()->count(),
                'available' => Product::query()->available()->count(),
            ],
            'averages' => [
                'loyalty_cards_per_business' => $businessesTotal > 0
                    ? round($loyaltyCardsTotal / $businessesTotal, 2)
                    : 0.0,
                'customers_per_business' => $businessesTotal > 0
                    ? round($customersTotal / $businessesTotal, 2)
                    : 0.0,
                'stamps_per_customer' => $customersTotal > 0
                    ? round($stampsTotal / $customersTotal, 2)
                    : 0.0,
            ],
            'growth' => $this->growthSeries(6),
            'top_businesses' => $this->topBusinesses(),
            'generated_at' => now()->toIso8601String(),
        ];
    }

    /**
     * Altas de negocios y clientes por mes (últimos `$months` meses).
     *
     * @return array<int, array<string, mixed>>
     */
    private function growthSeries(int $months): array
    {
        $series = [];
        $cursor = now()->startOfMonth()->subMonths($months - 1);

        for ($i = 0; $i < $months; $i++) {
            $start = $cursor->copy();
            $end = $cursor->copy()->endOfMonth();

            $series[] = [
                'month' => $start->format('Y-m'),
                'businesses' => Business::query()->whereBetween('created_at', [$start, $end])->count(),
                'customers' => CustomerCard::query()->whereBetween('created_at', [$start, $end])->count(),
            ];

            $cursor->addMonth();
        }

        return $series;
    }

    /**
     * Negocios con más clientes (para el ranking del panel).
     *
     * @return array<int, array<string, mixed>>
     */
    private function topBusinesses(int $limit = 5): array
    {
        return Business::query()
            ->withCount(['customerCards', 'stamps'])
            ->orderByDesc('customer_cards_count')
            ->orderBy('name')
            ->limit($limit)
            ->get(['id', 'name', 'slug', 'logo_path', 'is_active'])
            ->map(fn (Business $business): array => [
                'id' => $business->getKey(),
                'name' => $business->name,
                'slug' => $business->slug,
                'logo_url' => $business->logo_url,
                'is_active' => (bool) $business->is_active,
                'customers_count' => (int) $business->customer_cards_count,
                'stamps_count' => (int) $business->stamps_count,
            ])
            ->all();
    }

    /**
     * Métricas de un negocio concreto (detalle del panel).
     *
     * @return array<string, mixed>
     */
    private function businessStats(Business $business): array
    {
        $businessId = $business->getKey();

        $cards = CustomerCard::query()->where('business_id', $businessId);
        $stamps = Stamp::query()->where('business_id', $businessId);
        $redemptions = Redemption::query()->where('business_id', $businessId);

        $rewardsEarned = (int) (clone $cards)->sum('rewards_earned');
        $rewardsRedeemed = (int) (clone $cards)->sum('rewards_redeemed');
        $customersTotal = (clone $cards)->count();
        $stampsTotal = (clone $stamps)->count();

        return [
            'loyalty_cards_total' => LoyaltyCard::query()->where('business_id', $businessId)->count(),
            'loyalty_cards_active' => LoyaltyCard::query()->where('business_id', $businessId)->where('is_active', true)->count(),
            'customers_total' => $customersTotal,
            'customers_active' => (clone $cards)->where('status', CardStatus::Active->value)->count(),
            'customers_completed' => (clone $cards)->where('status', CardStatus::Completed->value)->count(),
            'stamps_total' => $stampsTotal,
            'stamps_last_30_days' => (clone $stamps)->where('stamped_at', '>=', now()->subDays(30))->count(),
            'rewards_earned' => $rewardsEarned,
            'rewards_redeemed' => $rewardsRedeemed,
            'rewards_available' => max(0, $rewardsEarned - $rewardsRedeemed),
            'rewards_defined' => Reward::query()->where('business_id', $businessId)->count(),
            'redemptions_total' => (clone $redemptions)->count(),
            'redemptions_pending' => (clone $redemptions)->where('status', RedemptionStatus::Pending->value)->count(),
            'redemptions_completed' => (clone $redemptions)->where('status', RedemptionStatus::Completed->value)->count(),
            'promotions_total' => Promotion::query()->where('business_id', $businessId)->count(),
            'promotions_active' => Promotion::query()->where('business_id', $businessId)->active()->count(),
            'products_total' => Product::query()->where('business_id', $businessId)->count(),
            'avg_stamps_per_customer' => $customersTotal > 0
                ? round($stampsTotal / $customersTotal, 2)
                : 0.0,
            'generated_at' => now()->toIso8601String(),
        ];
    }

    // -----------------------------------------------------------------
    // Utilidades de escritura
    // -----------------------------------------------------------------

    /**
     * Resuelve el propietario del negocio: usuario existente o uno nuevo.
     *
     * @param  array<string, mixed>|null  $ownerData
     */
    private function resolveOwner(?array $ownerData, int|string|null $ownerId): User
    {
        if ($ownerId !== null) {
            return User::query()->findOrFail((int) $ownerId);
        }

        return User::create([
            'name' => $ownerData['name'],
            'email' => $ownerData['email'],
            'phone' => $ownerData['phone'] ?? null,
            'password' => $ownerData['password'],
            'role' => UserRole::Negocio,
            'is_active' => filter_var($ownerData['is_active'] ?? true, FILTER_VALIDATE_BOOLEAN),
        ]);
    }

    /**
     * Slug único a partir de un nombre o de un slug ya enviado.
     */
    private function uniqueSlug(?string $source, ?string $ignoreId = null): string
    {
        $base = Str::slug((string) $source);
        $base = $base !== '' ? $base : 'negocio';

        $slug = $base;
        $suffix = 2;

        while (Business::withTrashed()
            ->where('slug', $slug)
            ->when($ignoreId !== null, fn (Builder $query): Builder => $query->whereKeyNot($ignoreId))
            ->exists()
        ) {
            $slug = $base.'-'.$suffix;
            $suffix++;
        }

        return $slug;
    }

    /**
     * Reemplaza las categorías globales del negocio respetando el orden enviado.
     *
     * @param  array<int, int|string>  $categoryIds
     */
    private function syncCategories(Business $business, array $categoryIds): void
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
     * Separa las imágenes del resto de atributos del negocio.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, string|null>
     */
    private function extractImages(array &$data): array
    {
        $images = [];

        foreach (array_keys(self::ASSET_FIELDS) as $input) {
            if (array_key_exists($input, $data)) {
                $images[$input] = $data[$input];
                unset($data[$input]);
            }
        }

        return $images;
    }

    /**
     * Guarda las imágenes enviadas y devuelve las columnas que deben persistirse.
     *
     * @param  array<string, string|null>  $images
     * @param  array<int, string>  $storedPaths
     * @param  array<int, string>  $pathsToDelete
     * @return array<string, string|null>
     */
    private function storeImages(
        Business $business,
        array $images,
        array &$storedPaths,
        array &$pathsToDelete,
    ): array {
        $attributes = [];

        foreach (self::ASSET_FIELDS as $input => $column) {
            if (! array_key_exists($input, $images)) {
                continue;
            }

            $value = $images[$input];

            if ($value === null) {
                if (filled($business->{$column})) {
                    $pathsToDelete[] = $business->{$column};
                }

                $attributes[$column] = null;

                continue;
            }

            $path = $this->assets->storePublicBase64Image(
                $value,
                $this->assets->businessDirectory((string) $business->getKey()),
            );

            $storedPaths[] = $path;

            if (filled($business->{$column}) && $business->{$column} !== $path) {
                $pathsToDelete[] = $business->{$column};
            }

            $attributes[$column] = $path;
        }

        return $attributes;
    }

    /**
     * @param  array<int, string>  $paths
     */
    private function deleteAssets(array $paths): void
    {
        foreach ($paths as $path) {
            $this->assets->delete($path);
        }
    }

    /**
     * Acepta una lista o una cadena separada por comas y la normaliza.
     *
     * @return array<int, string>
     */
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
}
