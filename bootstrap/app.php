<?php

use App\Http\Middleware\EnsureUserHasRole;
use App\Http\Middleware\ForceJsonResponse;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Validation\ValidationException;
use Laravel\Passport\Exceptions\MissingScopeException;
use Illuminate\Auth\Access\AuthorizationException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Todas las respuestas de /api/* se negocian como JSON (incluidos errores).
        $middleware->api(prepend: [
            ForceJsonResponse::class,
        ]);

        // Middleware de rol: ->middleware('role:negocio,admin')
        $middleware->alias([
            'role' => EnsureUserHasRole::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // /oauth/* queda fuera a propósito: la pantalla de consentimiento debe
        // poder redirigir al login en lugar de devolver JSON.
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        $exceptions->render(function (AuthenticationException $e, Request $request) {
            if ($request->is('api/*')) {
                return response()->json([
                    'message' => 'No autenticado: falta un token válido o ha expirado.',
                    'error' => 'unauthenticated',
                ], 401);
            }
        });

        $exceptions->render(function (AccessDeniedHttpException|AuthorizationException $e, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            // Passport lanza MissingScopeException (que extiende AuthorizationException):
            // el handler la convierte en AccessDeniedHttpException y conserva la original
            // en getPrevious(), de ahí que el scope se compruebe aquí.
            $missingScope = $e->getPrevious();

            if ($missingScope instanceof MissingScopeException) {
                return response()->json([
                    'message' => 'El token no tiene los scopes necesarios para este recurso.',
                    'error' => 'missing_scope',
                    'required_scopes' => $missingScope->scopes(),
                ], 403);
            }

            return response()->json([
                'message' => $e->getMessage() ?: 'No tienes permisos para realizar esta acción.',
                'error' => 'forbidden',
            ], 403);
        });

        $exceptions->render(function (ModelNotFoundException $e, Request $request) {
            if ($request->is('api/*')) {
                return response()->json([
                    'message' => 'El recurso solicitado no existe.',
                    'error' => 'not_found',
                ], 404);
            }
        });

        $exceptions->render(function (NotFoundHttpException $e, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            // `abort(404, 'mensaje propio')` conserva su mensaje; el 404 de router
            // (mensaje vacío) y el de route model binding —cuyo mensaje es
            // "No query results for model [...]"— reciben uno genérico en español.
            $message = trim($e->getMessage());
            $fromBinding = $e->getPrevious() instanceof ModelNotFoundException;

            return response()->json([
                'message' => ($message === '' || $fromBinding) ? 'El recurso solicitado no existe.' : $message,
                'error' => 'not_found',
            ], 404);
        });

        $exceptions->render(function (TooManyRequestsHttpException $e, Request $request) {
            if ($request->is('api/*')) {
                return response()->json([
                    'message' => 'Demasiadas peticiones. Inténtalo de nuevo en unos segundos.',
                    'error' => 'too_many_requests',
                ], 429);
            }
        });

        // 422 con el mismo sobre que el resto de errores de /api/*.
        $exceptions->render(function (ValidationException $e, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            $messages = Arr::flatten($e->errors());

            return response()->json([
                'message' => $messages[0] ?? 'Los datos enviados no son correctos.',
                'error' => 'validation_error',
                'errors' => $e->errors(),
            ], $e->status);
        });

        // Red de seguridad: cualquier otra HttpException (abort(403, '...'), 405, ...)
        // también viaja con un código de error estable. Va al final para no interceptar
        // a las anteriores.
        $exceptions->render(function (HttpException $e, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            $status = $e->getStatusCode();

            // Los 5xx se dejan al handler por defecto: en depuración muestran la traza.
            if ($status >= 500) {
                return null;
            }

            $error = match ($status) {
                400 => 'bad_request',
                401 => 'unauthenticated',
                403 => 'forbidden',
                404 => 'not_found',
                405 => 'method_not_allowed',
                409 => 'conflict',
                419 => 'token_mismatch',
                422 => 'validation_error',
                429 => 'too_many_requests',
                default => 'http_error',
            };

            $fallback = match ($status) {
                405 => 'El método HTTP no está permitido para esta ruta.',
                419 => 'La sesión ha caducado, vuelve a iniciar sesión.',
                default => 'La petición no se pudo completar.',
            };

            return response()->json([
                'message' => $e->getMessage() ?: $fallback,
                'error' => $error,
            ], $status, $e->getHeaders());
        });
    })->create();
