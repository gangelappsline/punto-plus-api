<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Restringe una ruta a uno o varios roles: ->middleware('role:negocio,admin').
 */
class EnsureUserHasRole
{
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $user = $request->user();

        if ($user === null) {
            return response()->json([
                'message' => 'No autenticado.',
                'error' => 'unauthenticated',
            ], 401);
        }

        if (! $user->is_active) {
            return response()->json([
                'message' => 'Tu cuenta está desactivada. Contacta con soporte.',
                'error' => 'account_disabled',
            ], 403);
        }

        if ($roles !== [] && ! $user->hasRole(...$roles)) {
            return response()->json([
                'message' => 'Tu rol ('.$user->role->value.') no tiene acceso a este recurso.',
                'error' => 'forbidden',
            ], 403);
        }

        return $next($request);
    }
}
