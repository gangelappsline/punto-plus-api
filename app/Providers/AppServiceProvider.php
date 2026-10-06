<?php

namespace App\Providers;

use App\Events\CardCompleted;
use App\Events\RedemptionProcessed;
use App\Events\RedemptionRequested;
use App\Events\StampRegistered;
use App\Events\StampVoided;
use App\Listeners\LogLoyaltyActivity;
use App\Listeners\NotifyCardCompleted;
use App\Models\Business;
use App\Models\CustomerCard;
use App\Models\LoyaltyCard;
use App\Models\Promotion;
use App\Models\Redemption;
use App\Models\Reward;
use App\Models\Stamp;
use App\Models\User;
use App\Policies\BusinessPolicy;
use App\Policies\CustomerCardPolicy;
use App\Policies\LoyaltyCardPolicy;
use App\Policies\PromotionPolicy;
use App\Policies\RedemptionPolicy;
use App\Policies\RewardPolicy;
use App\Policies\StampPolicy;
use Carbon\CarbonInterval;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Laravel\Passport\Passport;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        $this->configurePassport();
        $this->registerPolicies();
        $this->registerRateLimiters();
        $this->registerEventListeners();
    }

    /**
     * Scopes, vigencias de token y vista de consentimiento personalizada.
     */
    private function configurePassport(): void
    {
        Passport::tokensExpireIn(CarbonInterval::minutes((int) config('punto_plus.tokens.access_minutes')));
        Passport::refreshTokensExpireIn(CarbonInterval::days((int) config('punto_plus.tokens.refresh_days')));
        Passport::personalAccessTokensExpireIn(CarbonInterval::days((int) config('punto_plus.tokens.personal_access_days')));

        Passport::tokensCan(config('punto_plus.scopes'));
        Passport::setDefaultScope((string) config('punto_plus.default_scope'));

        // Vista propia de autorización (scopes cliente/negocio/admin).
        Passport::authorizationView('auth.oauth.authorize');

        // El password grant es necesario para /api/auth/login; PKCE (Authorization
        // Code + public client) sigue siendo el flujo recomendado para la app móvil.
        if (config('punto_plus.oauth.password_grant_enabled')) {
            Passport::enablePasswordGrant();
        }

        // En pruebas se usan claves de fixture (tests/Fixtures/keys).
        if ($keysPath = config('punto_plus.oauth.keys_path')) {
            $path = str_starts_with((string) $keysPath, '/') ? (string) $keysPath : base_path((string) $keysPath);

            if (is_dir($path)) {
                Passport::loadKeysFrom($path);
            }
        }
    }

    private function registerPolicies(): void
    {
        Gate::policy(Business::class, BusinessPolicy::class);
        Gate::policy(LoyaltyCard::class, LoyaltyCardPolicy::class);
        Gate::policy(CustomerCard::class, CustomerCardPolicy::class);
        Gate::policy(Promotion::class, PromotionPolicy::class);
        Gate::policy(Reward::class, RewardPolicy::class);
        Gate::policy(Redemption::class, RedemptionPolicy::class);
        Gate::policy(Stamp::class, StampPolicy::class);

        // El rol admin tiene acceso global (soporte y moderación de la plataforma).
        Gate::before(fn (User $user): ?bool => $user->isAdmin() ? true : null);
    }

    private function registerRateLimiters(): void
    {
        // Endpoints de autenticación: 10 intentos por minuto y por email+IP.
        RateLimiter::for('auth', fn (Request $request): Limit => Limit::perMinute(10)
            ->by(mb_strtolower((string) $request->input('email')).'|'.$request->ip()));

        // API general: más margen para usuarios autenticados.
        RateLimiter::for('api', fn (Request $request): Limit => $request->user() !== null
            ? Limit::perMinute(120)->by('user:'.$request->user()->getAuthIdentifier())
            : Limit::perMinute(60)->by('ip:'.$request->ip()));
    }

    private function registerEventListeners(): void
    {
        Event::listen(CardCompleted::class, NotifyCardCompleted::class);

        Event::listen([
            StampRegistered::class,
            StampVoided::class,
            RedemptionRequested::class,
            RedemptionProcessed::class,
        ], LogLoyaltyActivity::class);
    }
}
