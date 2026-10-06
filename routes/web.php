<?php

use App\Http\Controllers\Web\AuthorizationViewController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Rutas web
|--------------------------------------------------------------------------
|
| La API es stateless, pero el flujo OAuth2 "Authorization Code" (app móvil)
| necesita una sesión en el navegador para mostrar la pantalla de
| consentimiento (/oauth/authorize) y para aprobar o denegar el acceso.
|
| Estas rutas son las mínimas imprescindibles para ese flujo.
|
*/

Route::get('/', fn () => response()->json([
    'name' => config('app.name'),
    'api' => 'Punto Plus API',
    'version' => '1.0.0',
    'docs' => url(config('services.punto_plus.docs_url')),
    'health' => url('/up'),
    'oauth' => [
        'authorize' => url('/oauth/authorize'),
        'token' => url('/oauth/token'),
    ],
]))->name('home');

// Login por sesión: sólo para autorizar clientes OAuth2 desde el navegador.
Route::middleware('guest:web')->group(function (): void {
    Route::get('login', [AuthorizationViewController::class, 'showLogin'])->name('login');
    Route::post('login', [AuthorizationViewController::class, 'login'])->name('login.store');
});

Route::post('logout', [AuthorizationViewController::class, 'logout'])
    ->middleware('auth:web')
    ->name('logout');
