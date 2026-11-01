<?php

namespace App\Http\Controllers\Api\Client;

use App\Http\Controllers\Controller;
use App\Http\Resources\Client\BusinessResource;
use App\Models\Business;
use App\Models\CustomerCard;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * Negocios cerca de la ubicación del cliente (por defecto, 10 km a la redonda).
 *
 * La distancia se calcula con la fórmula de Haversine. Para no obligar a la
 * base de datos a hacer trigonometría (no disponible en todos los motores), se
 * filtra primero por una caja delimitadora aprovechando `latitude`/`longitude`
 * y el recorte exacto se hace en PHP; el resultado se ordena por distancia y se
 * pagina con `LengthAwarePaginator`.
 */
class NearMeController extends Controller
{
    /** Radio por defecto de la búsqueda (km). */
    private const DEFAULT_RADIUS_KM = 10.0;

    /** Radio máximo aceptado (km). */
    private const MAX_RADIUS_KM = 100.0;

    /** Kilómetros que equivalen a un grado de latitud. */
    private const KM_PER_DEGREE = 111.045;

    /** Radio medio terrestre (km). */
    private const EARTH_RADIUS_KM = 6371.0;

    /**
     * GET /api/client/near-me?latitude=..&longitude=..
     *
     * Filtros: `radius` (km, por defecto 10), `search`, `category`, `per_page`,
     * `page`.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $filters = $request->validate([
            'latitude' => ['required', 'numeric', 'between:-90,90'],
            'longitude' => ['required', 'numeric', 'between:-180,180'],
            'radius' => ['nullable', 'numeric', 'min:0.1', 'max:'.self::MAX_RADIUS_KM],
            'search' => ['nullable', 'string', 'max:120'],
            'category' => ['nullable', 'string', 'max:80'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:'.config('punto_plus.per_page.max')],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);

        $latitude = (float) $filters['latitude'];
        $longitude = (float) $filters['longitude'];
        $radius = (float) ($filters['radius'] ?? self::DEFAULT_RADIUS_KM);

        $nearby = $this->nearbyBusinesses($filters, $latitude, $longitude, $radius)
            ->map(function (Business $business) use ($latitude, $longitude): Business {
                $business->setAttribute('distance_km', $this->distanceKm(
                    $latitude,
                    $longitude,
                    (float) $business->latitude,
                    (float) $business->longitude,
                ));

                return $business;
            })
            ->filter(fn (Business $business): bool => $business->distance_km <= $radius)
            ->sortBy('distance_km')
            ->values();

        // Negocios con los que el cliente tiene o ha tenido tarjeta.
        $businessIdsWithCard = CustomerCard::withTrashed()
            ->where('user_id', $request->user()->getAuthIdentifier())
            ->distinct()
            ->pluck('business_id')
            ->all();

        $nearby->each(function (Business $business) use ($businessIdsWithCard): void {
            $business->setAttribute('has_card', in_array($business->getKey(), $businessIdsWithCard, true));
        });

        $perPage = $this->perPage($request);
        $page = max(1, (int) $request->integer('page', 1));

        $paginator = new LengthAwarePaginator(
            $nearby->forPage($page, $perPage)->values(),
            $nearby->count(),
            $perPage,
            $page,
            ['path' => $request->url(), 'query' => $request->query()],
        );

        return BusinessResource::collection($paginator)->additional([
            'center' => [
                'latitude' => $latitude,
                'longitude' => $longitude,
                'radius_km' => $radius,
            ],
        ]);
    }

    /**
     * Negocios activos dentro de la caja delimitadora del radio pedido.
     *
     * @param  array<string, mixed>  $filters
     * @return Collection<int, Business>
     */
    private function nearbyBusinesses(array $filters, float $latitude, float $longitude, float $radius): Collection
    {
        $latitudeDelta = $radius / self::KM_PER_DEGREE;

        // El grado de longitud se acorta al alejarnos del ecuador.
        $longitudeDelta = $radius / (self::KM_PER_DEGREE * max(cos(deg2rad($latitude)), 0.01));

        $minLongitude = $longitude - $longitudeDelta;
        $maxLongitude = $longitude + $longitudeDelta;

        return Business::query()
            ->active()
            ->whereNotNull('latitude')
            ->whereNotNull('longitude')
            ->whereBetween('latitude', [
                max(-90.0, $latitude - $latitudeDelta),
                min(90.0, $latitude + $latitudeDelta),
            ])
            // Si la caja cruza el meridiano ±180 se omite este filtro y el
            // recorte exacto por distancia se encarga del resto.
            ->when(
                $minLongitude >= -180.0 && $maxLongitude <= 180.0,
                fn (Builder $query): Builder => $query->whereBetween('longitude', [$minLongitude, $maxLongitude]),
            )
            ->when(filled($filters['search'] ?? null), function (Builder $query) use ($filters): void {
                $like = '%'.$filters['search'].'%';

                $query->where(function (Builder $query) use ($like): void {
                    $query->where('name', 'like', $like)
                        ->orWhere('description', 'like', $like)
                        ->orWhere('category', 'like', $like);
                });
            })
            ->when(filled($filters['category'] ?? null), fn (Builder $query): Builder => $query->where('category', $filters['category']))
            ->with(['categories.category'])
            ->withCount([
                'loyaltyCards as active_loyalty_cards_count' => fn (Builder $query): Builder => $query->where('is_active', true),
                'promotions as active_promotions_count' => fn (Builder $query): Builder => $query->active(),
            ])
            ->get();
    }

    /**
     * Distancia en kilómetros entre dos coordenadas (Haversine).
     */
    private function distanceKm(float $latitude1, float $longitude1, float $latitude2, float $longitude2): float
    {
        $latitudeDelta = deg2rad($latitude2 - $latitude1);
        $longitudeDelta = deg2rad($longitude2 - $longitude1);

        $a = sin($latitudeDelta / 2) ** 2
            + cos(deg2rad($latitude1)) * cos(deg2rad($latitude2)) * sin($longitudeDelta / 2) ** 2;

        return round(self::EARTH_RADIUS_KM * 2 * atan2(sqrt($a), sqrt(1 - $a)), 2);
    }
}
