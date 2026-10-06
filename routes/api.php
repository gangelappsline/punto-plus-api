<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\BusinessController;
use App\Http\Controllers\Api\CustomerCardController;
use App\Http\Controllers\Api\LoyaltyCardController;
use App\Http\Controllers\Api\PromotionController;
use App\Http\Controllers\Api\RedemptionController;
use App\Http\Controllers\Api\RewardController;
use App\Http\Controllers\Api\StampController;
use App\Http\Controllers\Api\UserController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Punto Plus (prefijo /api)
|--------------------------------------------------------------------------
|
| - Los endpoints de negocio requieren rol `negocio` (o `admin`).
| - Los endpoints de cliente sólo requieren un token válido: un dueño de
|   negocio también puede acumular sellos en otros comercios.
| - El scope OAuth2 acompaña al rol, pero la autorización fina la hacen las
|   Policies (un cliente nunca puede leer datos de otro cliente o negocio).
|
*/

// ---------------------------------------------------------------------------
// Autenticación (público)
// ---------------------------------------------------------------------------
Route::prefix('auth')->name('auth.')->middleware('throttle:auth')->group(function (): void {
    Route::post('register', [AuthController::class, 'register'])->name('register');
    Route::post('login', [AuthController::class, 'login'])->name('login');
    Route::post('refresh', [AuthController::class, 'refresh'])->name('refresh');
});

// ---------------------------------------------------------------------------
// Catálogo público de negocios (sin token)
// ---------------------------------------------------------------------------
Route::get('businesses', [BusinessController::class, 'index'])->name('businesses.index');
Route::get('businesses/{business}', [BusinessController::class, 'show'])->name('businesses.show');

// ---------------------------------------------------------------------------
// Rutas autenticadas (Bearer token de Passport)
// ---------------------------------------------------------------------------
Route::middleware('auth:api')->group(function (): void {

    Route::post('auth/logout', [AuthController::class, 'logout'])->name('auth.logout');
    Route::post('auth/logout-all', [AuthController::class, 'logoutAll'])->name('auth.logout-all');

    // Perfil del usuario autenticado (clientes y negocios).
    Route::get('user', [UserController::class, 'show'])->name('user.show');
    Route::put('user', [UserController::class, 'update'])->name('user.update');
    Route::put('user/password', [UserController::class, 'updatePassword'])->name('user.password');

    /*
    |--------------------------------------------------------------------------
    | Área cliente
    |--------------------------------------------------------------------------
    */
    Route::prefix('customer')->name('customer.')->group(function (): void {
        Route::get('cards', [CustomerCardController::class, 'index'])->name('cards.index');
        Route::post('cards/join', [CustomerCardController::class, 'join'])->name('cards.join');
        Route::get('cards/{customerCard}', [CustomerCardController::class, 'show'])->name('cards.show');
        Route::delete('cards/{customerCard}', [CustomerCardController::class, 'destroy'])->name('cards.leave');
        Route::get('cards/{customerCard}/stamps', [CustomerCardController::class, 'stamps'])->name('cards.stamps');
        Route::get('cards/{customerCard}/qr', [CustomerCardController::class, 'qr'])->name('cards.qr');
        Route::get('cards/{customerCard}/rewards', [CustomerCardController::class, 'rewards'])->name('cards.rewards');

        Route::post('cards/{customerCard}/redeem', [RedemptionController::class, 'store'])->name('cards.redeem');

        Route::get('redemptions', [RedemptionController::class, 'index'])->name('redemptions.index');
        Route::get('redemptions/{redemption}', [RedemptionController::class, 'show'])->name('redemptions.show');
        Route::post('redemptions/{redemption}/cancel', [RedemptionController::class, 'cancel'])->name('redemptions.cancel');

        // Promociones activas de los negocios en los que el cliente tiene tarjeta.
        Route::get('promotions', [PromotionController::class, 'forCustomer'])->name('promotions.index');
    });

    /*
    |--------------------------------------------------------------------------
    | Área negocio  (middleware: role:negocio)
    |--------------------------------------------------------------------------
    */
    Route::prefix('business')->name('business.')->middleware('role:negocio,admin')->group(function (): void {

        // Perfil y configuración del negocio
        Route::get('profile', [BusinessController::class, 'mine'])->name('profile.show');
        Route::put('profile', [BusinessController::class, 'updateMine'])->name('profile.update');
        Route::put('card-settings', [BusinessController::class, 'updateCardSettings'])->name('card-settings.update');

        // Assets de marca: logo, fondo y sello por defecto
        Route::post('assets', [BusinessController::class, 'uploadAsset'])->name('assets.store');
        Route::delete('assets/{type}', [BusinessController::class, 'destroyAsset'])->name('assets.destroy');

        // Tarjetas de fidelidad
        Route::get('cards', [LoyaltyCardController::class, 'index'])->name('cards.index');
        Route::post('cards', [LoyaltyCardController::class, 'store'])->name('cards.store');
        Route::get('cards/{loyaltyCard}', [LoyaltyCardController::class, 'show'])->name('cards.show');
        Route::put('cards/{loyaltyCard}', [LoyaltyCardController::class, 'update'])->name('cards.update');
        Route::delete('cards/{loyaltyCard}', [LoyaltyCardController::class, 'destroy'])->name('cards.destroy');
        Route::post('cards/{loyaltyCard}/upload-assets', [LoyaltyCardController::class, 'uploadAssets'])->name('cards.assets');
        Route::post('cards/{loyaltyCard}/regenerate-join-code', [LoyaltyCardController::class, 'regenerateJoinCode'])->name('cards.join-code');
        Route::post('cards/{loyaltyCard}/duplicate', [LoyaltyCardController::class, 'duplicate'])->name('cards.duplicate');
        Route::get('cards/{loyaltyCard}/qr', [LoyaltyCardController::class, 'qr'])->name('cards.qr');
        Route::get('cards/{loyaltyCard}/stats', [LoyaltyCardController::class, 'stats'])->name('cards.stats');

        // Recompensas de cada tarjeta
        Route::get('cards/{loyaltyCard}/rewards', [RewardController::class, 'index'])->name('cards.rewards.index');
        Route::post('cards/{loyaltyCard}/rewards', [RewardController::class, 'store'])->name('cards.rewards.store');
        Route::put('rewards/{reward}', [RewardController::class, 'update'])->name('rewards.update');
        Route::delete('rewards/{reward}', [RewardController::class, 'destroy'])->name('rewards.destroy');

        // Promociones
        Route::get('promotions', [PromotionController::class, 'index'])->name('promotions.index');
        Route::post('promotions', [PromotionController::class, 'store'])->name('promotions.store');
        Route::get('promotions/{promotion}', [PromotionController::class, 'show'])->name('promotions.show');
        Route::put('promotions/{promotion}', [PromotionController::class, 'update'])->name('promotions.update');
        Route::delete('promotions/{promotion}', [PromotionController::class, 'destroy'])->name('promotions.destroy');

        // Sellos: el negocio escanea el QR del cliente
        Route::post('stamps/scan', [StampController::class, 'scan'])->name('stamps.scan');
        Route::get('stamps/recent', [StampController::class, 'recent'])->name('stamps.recent');
        Route::get('stamps', [StampController::class, 'index'])->name('stamps.index');
        Route::delete('stamps/{stamp}', [StampController::class, 'destroy'])->name('stamps.destroy');

        // Clientes del negocio (tarjetas emitidas)
        Route::get('customers', [CustomerCardController::class, 'indexForBusiness'])->name('customers.index');
        Route::get('customers/{customerCard}', [CustomerCardController::class, 'showForBusiness'])->name('customers.show');

        // Canjes
        Route::get('redemptions', [RedemptionController::class, 'businessIndex'])->name('redemptions.index');
        Route::post('redemptions/complete', [RedemptionController::class, 'completeByCode'])->name('redemptions.complete');
        Route::post('redemptions/{redemption}/approve', [RedemptionController::class, 'approve'])->name('redemptions.approve');
        Route::post('redemptions/{redemption}/reject', [RedemptionController::class, 'reject'])->name('redemptions.reject');
    });
});
