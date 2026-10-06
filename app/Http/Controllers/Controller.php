<?php

namespace App\Http\Controllers;

use App\Models\Business;
use App\Models\User;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\Request;

abstract class Controller
{
    /**
     * Laravel 11+ ya no incluye este trait en el controlador base; lo añadimos
     * para poder usar `$this->authorize(...)` con las Policies del dominio.
     */
    use AuthorizesRequests;

    /**
     * Tamaño de página saneado (evita que un cliente pida 10.000 registros).
     */
    protected function perPage(Request $request): int
    {
        return (int) min(
            max(1, (int) $request->integer('per_page', config('punto_plus.per_page.default'))),
            (int) config('punto_plus.per_page.max'),
        );
    }

    /**
     * Identificador del access token con el que se autenticó la petición.
     *
     * Passport 13 expone el token como ``AccessToken`` (decorador) o como
     * ``Token`` (modelo) según el flujo, así que se resuelve en ambos casos.
     */
    protected function currentTokenId(?Request $request = null): ?string
    {
        $token = ($request ?? request())->user()?->token();

        return match (true) {
            $token instanceof \Laravel\Passport\AccessToken => $token->oauth_access_token_id ?? null,
            $token instanceof \Laravel\Passport\Token => $token->getKey(),
            default => null,
        };
    }

    /**
     * Negocio sobre el que opera el usuario autenticado.
     *
     * Un `admin` puede indicar `business_id` para operar en nombre de un negocio.
     */
    protected function businessOrFail(Request $request): Business
    {
        /** @var User $user */
        $user = $request->user();

        $business = $user->business()->first();

        if ($business === null && $request->filled('business_id') && $user->isAdmin()) {
            $business = Business::find($request->string('business_id')->toString());
        }

        abort_if($business === null, 404, 'No tienes un negocio asociado. Regístrate como negocio para gestionar tarjetas.');

        abort_unless($user->isAdmin() || $user->ownsBusiness($business), 403, 'Ese negocio no te pertenece.');

        return $business;
    }
}
