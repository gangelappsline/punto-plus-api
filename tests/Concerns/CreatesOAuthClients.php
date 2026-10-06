<?php

namespace Tests\Concerns;

use App\Models\User;
use Laravel\Passport\Client;
use Laravel\Passport\ClientRepository;
use Laravel\Passport\Passport;

/**
 * Crea en caliente los clientes OAuth2 necesarios para las pruebas y deja las
 * credenciales del password grant en la configuración (el secreto sólo se
 * conoce al crear el cliente, porque se guarda hasheado).
 */
trait CreatesOAuthClients
{
    protected ?Client $passwordGrantClient = null;

    protected function createOAuthClients(): void
    {
        $clients = app(ClientRepository::class);
        $provider = config('auth.guards.api.provider', 'users');

        // Personal access client (para el modo degradado de /api/auth/login).
        try {
            $clients->personalAccessClient($provider);
        } catch (\RuntimeException) {
            $clients->createPersonalAccessGrantClient('Testing personal access', $provider);
        }

        $this->passwordGrantClient = $clients->createPasswordGrantClient(
            'Testing password grant',
            $provider,
            confidential: true,
        );

        config([
            'punto_plus.oauth.password_client_id' => $this->passwordGrantClient->getKey(),
            'punto_plus.oauth.password_client_secret' => $this->passwordGrantClient->plainSecret,
        ]);
    }

    /**
     * Autentica la petición con el token del usuario y el scope de su rol
     * (equivalente a `Passport::actingAs()` pero con el scope correcto).
     *
     * @param  array<int, string>|null  $scopes
     */
    protected function actingAsApi(User $user, ?array $scopes = null): User
    {
        return Passport::actingAs($user, $scopes ?? [$user->role->scope()]);
    }

    /**
     * Cliente público PKCE (app móvil).
     *
     * @param  array<int, string>  $redirectUris
     */
    protected function createPkceClient(array $redirectUris = ['https://app.test/callback']): Client
    {
        return app(ClientRepository::class)->createAuthorizationCodeGrantClient(
            'Testing PKCE',
            $redirectUris,
            confidential: false,
        );
    }
}
