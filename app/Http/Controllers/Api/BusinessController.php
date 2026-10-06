<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Business\UpdateBusinessRequest;
use App\Http\Requests\Business\UpdateCardSettingsRequest;
use App\Http\Requests\Business\UploadAssetRequest;
use App\Http\Resources\BusinessResource;
use App\Models\Business;
use App\Services\AssetService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class BusinessController extends Controller
{
    /**
     * Mapa `tipo de asset` => columna en base de datos.
     */
    private const ASSET_FIELDS = [
        'logo' => 'logo_path',
        'background' => 'background_path',
        'stamp_icon' => 'stamp_icon_path',
    ];

    public function __construct(private readonly AssetService $assets)
    {
    }

    /**
     * GET /api/businesses — catálogo público de negocios.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:120'],
            'city' => ['nullable', 'string', 'max:100'],
            'category' => ['nullable', 'string', 'max:80'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:'.config('punto_plus.per_page.max')],
        ]);

        $businesses = Business::query()
            ->active()
            ->search($filters['search'] ?? null)
            ->inCity($filters['city'] ?? null)
            ->inCategory($filters['category'] ?? null)
            ->withCount('loyaltyCards')
            ->orderBy('name')
            ->paginate($this->perPage($request))
            ->withQueryString();

        return BusinessResource::collection($businesses);
    }

    /**
     * GET /api/businesses/{business}
     */
    public function show(Business $business): BusinessResource
    {
        $this->authorize('view', $business);

        return new BusinessResource(
            $business
                ->load(['owner', 'loyaltyCards' => fn ($query) => $query->active()->withCount('rewards')])
                ->loadCount(['loyaltyCards', 'customerCards'])
        );
    }

    /**
     * GET /api/business/profile — el negocio del usuario autenticado.
     */
    public function mine(Request $request): BusinessResource
    {
        $business = $this->businessOrFail($request);

        $this->authorize('view', $business);

        return new BusinessResource(
            $business->loadCount(['loyaltyCards', 'customerCards', 'promotions'])->load([
                'loyaltyCards' => fn ($query) => $query->withCount('customerCards'),
            ])
        );
    }

    /**
     * PUT /api/business/profile
     */
    public function updateMine(UpdateBusinessRequest $request): BusinessResource
    {
        $business = $this->businessOrFail($request);

        $this->authorize('update', $business);

        $business->fill($request->validated())->save();

        return new BusinessResource($business->loadCount(['loyaltyCards', 'customerCards']));
    }

    /**
     * PUT /api/business/card-settings — configuración por defecto de las tarjetas.
     */
    public function updateCardSettings(UpdateCardSettingsRequest $request): BusinessResource
    {
        $business = $this->businessOrFail($request);

        $this->authorize('manageBranding', $business);

        $business->card_settings = array_merge(
            $business->card_settings ?? [],
            $request->validated()
        );

        $business->save();

        return new BusinessResource($business);
    }

    /**
     * POST /api/business/assets — sube logo, fondo o icono de sello.
     */
    public function uploadAsset(UploadAssetRequest $request): BusinessResource
    {
        $business = $this->businessOrFail($request);

        $this->authorize('manageBranding', $business);

        $field = self::ASSET_FIELDS[$request->validated('type')];

        $business->{$field} = $this->assets->replace(
            $business->{$field},
            $request->file('file'),
            $this->assets->businessDirectory($business->getKey()),
        );

        $business->save();

        return new BusinessResource($business);
    }

    /**
     * DELETE /api/business/assets/{type}
     */
    public function destroyAsset(Request $request, string $type): BusinessResource
    {
        abort_unless(array_key_exists($type, self::ASSET_FIELDS), 404, 'Tipo de asset no soportado (logo, background o stamp_icon).');

        $business = $this->businessOrFail($request);

        $this->authorize('manageBranding', $business);

        $field = self::ASSET_FIELDS[$type];

        $this->assets->delete($business->{$field});

        $business->{$field} = null;
        $business->save();

        return new BusinessResource($business);
    }

}
