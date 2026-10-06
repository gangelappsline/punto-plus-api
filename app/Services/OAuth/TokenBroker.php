<?php

namespace App\Services\OAuth;

use App\Exceptions\OAuthConfigurationException;
use App\Models\User;
use App\Support\OAuth\TokenResponse;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Laravel\Passport\Client;
use Laravel\Passport\ClientRepository;
use Laravel\Passport\Http\Controllers\AccessTokenController;
use Laravel\Passport\Passport;
use Nyholm\Psr7\Factory\Psr17Factory;

/**
 * Emite tokens OAuth2 (password grant y refresh grant) en nombre de la API.
 *
 * Estrategia:
 *   1. Dispatch interno: se llama al AuthorizationServer de Passport dentro del
 *      mismo proceso (sin petición HTTP, imprescindible en tests y en entornos
 *      donde el servidor no puede llamarse a sí mismo).
 *   2. Fallback HTTP: si el stack PSR-7 no está disponible, se hace POST a
 *      /oauth/token (config `punto_plus.oauth.token_url`).
 *
 * Así POST /api/auth/login convive con el flujo OAuth2 estándar sin duplicar
 * la lógica de emisión de tokens.
 */
final class TokenBroker
{
    private ?Client $passwordClient = null;

    private bool $passwordClientResolved = false;

    public function __construct(private readonly ClientRepository $clients)
    {
    }

    /**
     * ¿Hay un cliente `password grant` disponible para emitir tokens con refresh?
     */
    public function hasPasswordClient(): bool
    {
        return $this->passwordClient() !== null;
    }

    /**
     * @param  array<int, string>|null  $scopes
     */
    public function passwordGrant(string $email, string $password, ?array $scopes = null, ?string $deviceName = null): TokenResponse
    {
        $payload = [
            'grant_type' => 'password',
            'username' => $email,
            'password' => $password,
            'scope' => $this->scopeString($scopes),
        ];

        if (filled($deviceName)) {
            $payload['device_name'] = $deviceName;
        }

        return $this->request($payload);
    }

    public function refreshGrant(string $refreshToken, ?string $scope = null): TokenResponse
    {
        return $this->request([
            'grant_type' => 'refresh_token',
            'refresh_token' => $refreshToken,
            'scope' => $scope,
        ]);
    }

    /**
     * Token personal de Passport (modo degradado: sin refresh token).
     *
     * @param  array<int, string>|null  $scopes
     */
    public function personalAccessToken(User $user, ?string $deviceName = null, ?array $scopes = null): TokenResponse
    {
        $this->assertKeysAreConfigured();

        $token = $user->createToken(
            $deviceName ?: 'punto-plus-api',
            $scopes === null ? ['*'] : $scopes,
        );

        return new TokenResponse(
            accessToken: $token->accessToken,
            refreshToken: null,
            tokenType: 'Bearer',
            expiresIn: max(0, $token->token->expires_at->getTimestamp() - now()->getTimestamp()),
            scopes: $scopes ?? [],
        );
    }

    // -----------------------------------------------------------------
    // Internals
    // -----------------------------------------------------------------

    /**
     * @param  array<string, mixed>  $payload
     */
    private function request(array $payload): TokenResponse
    {
        $this->assertKeysAreConfigured();

        $payload = $this->withClientCredentials($payload);

        if ($this->shouldDispatchInternally()) {
            return $this->dispatchInternally($payload);
        }

        return $this->dispatchOverHttp($payload);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function withClientCredentials(array $payload): array
    {
        $client = $this->passwordClient() ?? throw OAuthConfigurationException::missingPasswordClient();

        $payload['client_id'] = $client->getKey();

        if (filled($client->plainSecret)) {
            $payload['client_secret'] = $client->plainSecret;
        } elseif (filled(config('punto_plus.oauth.password_client_secret'))) {
            $payload['client_secret'] = (string) config('punto_plus.oauth.password_client_secret');
        }

        return $payload;
    }

    private function shouldDispatchInternally(): bool
    {
        return (bool) config('punto_plus.oauth.internal_dispatch', true)
            && class_exists(Psr17Factory::class);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function dispatchInternally(array $payload): TokenResponse
    {
        $factory = new Psr17Factory;

        $psrRequest = $factory->createServerRequest('POST', $this->tokenUrl())
            ->withHeader('Content-Type', 'application/x-www-form-urlencoded')
            ->withParsedBody($payload);

        $response = app(AccessTokenController::class)->issueToken($psrRequest, $factory->createResponse(200));

        /** @var array<string, mixed>|null $data */
        $data = json_decode((string) $response->getContent(), true);

        if (! is_array($data)) {
            throw new \RuntimeException('Respuesta inválida del servidor OAuth2.');
        }

        return TokenResponse::fromArray($data);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function dispatchOverHttp(array $payload): TokenResponse
    {
        $response = Http::asForm()
            ->acceptJson()
            ->timeout(10)
            ->post($this->tokenUrl(), $payload);

        if ($response->failed()) {
            Log::warning('OAuth2 token request failed', [
                'status' => $response->status(),
                'body' => $response->json(),
            ]);

            $response->throw();
        }

        return TokenResponse::fromArray((array) $response->json());
    }

    private function tokenUrl(): string
    {
        return (string) (config('punto_plus.oauth.token_url') ?: url('/oauth/token'));
    }

    /**
     * Cliente `password grant` utilizable.
     *
     * Passport 13 exige secreto de cliente para este grant (el secreto se guarda
     * hasheado, así que sólo se conoce al crearlo: se persiste en el .env con
     * `php artisan punto-plus:oauth-clients --write-env`). Si no hay secreto
     * disponible se devuelve null y la API entra en modo degradado (token
     * personal, sin refresh) en lugar de fallar.
     */
    private function passwordClient(): ?Client
    {
        if ($this->passwordClientResolved) {
            return $this->passwordClient;
        }

        $this->passwordClientResolved = true;

        $configuredSecret = (string) config('punto_plus.oauth.password_client_secret');

        if (filled($id = config('punto_plus.oauth.password_client_id'))) {
            $client = $this->clients->findActive($id);

            if ($client === null) {
                Log::warning('PASSPORT_PASSWORD_CLIENT_ID apunta a un cliente inexistente o revocado.', ['id' => $id]);

                return $this->passwordClient = null;
            }

            if (blank($client->plainSecret) && blank($configuredSecret)) {
                Log::warning('El cliente password grant no tiene PASSPORT_PASSWORD_CLIENT_SECRET configurado; /api/auth/login emitirá tokens personales (sin refresh).');

                return $this->passwordClient = null;
            }

            return $this->passwordClient = $client;
        }

        // Sin configurar: sólo se acepta un cliente público (sin secreto), que es
        // el caso de un password grant "abierto" durante desarrollo/pruebas.
        $this->passwordClient = Passport::client()
            ->newQuery()
            ->where('revoked', false)
            ->get()
            ->first(fn (Client $client): bool => $client->hasGrantType('password')
                && $client->secret === null);

        return $this->passwordClient;
    }

    /**
     * @param  array<int, string>|null  $scopes
     */
    private function scopeString(?array $scopes): ?string
    {
        if ($scopes === null) {
            return null;
        }

        $scopes = array_values(array_filter($scopes));

        return $scopes === [] ? null : implode(' ', $scopes);
    }

    private function assertKeysAreConfigured(): void
    {
        if (config('passport.private_key') !== null && config('passport.private_key') !== '') {
            return;
        }

        if (! file_exists(Passport::keyPath('oauth-private.key'))) {
            throw OAuthConfigurationException::missingKeys();
        }
    }
}
