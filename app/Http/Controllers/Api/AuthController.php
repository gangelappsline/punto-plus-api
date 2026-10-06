<?php

namespace App\Http\Controllers\Api;

use App\Enums\UserRole;
use App\Exceptions\InvalidCredentialsException;
use App\Exceptions\OAuthConfigurationException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Requests\Auth\RefreshTokenRequest;
use App\Http\Requests\Auth\RegisterRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Services\OAuth\TokenBroker;
use App\Support\OAuth\TokenResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Laravel\Passport\Exceptions\OAuthServerException;
use Laravel\Passport\Passport;
use Throwable;

/**
 * Autenticación OAuth2 de la API.
 *
 * Los tokens se emiten siempre a través del AuthorizationServer de Passport
 * (password grant / refresh grant), de modo que /api/auth/login y
 * POST /oauth/token comparten exactamente las mismas reglas y vigencias.
 */
class AuthController extends Controller
{
    public function __construct(private readonly TokenBroker $broker)
    {
    }

    /**
     * POST /api/auth/register
     */
    public function register(RegisterRequest $request): JsonResponse
    {
        $data = $request->validated();
        $role = UserRole::from($data['role']);

        $user = DB::transaction(function () use ($data, $role): User {
            $user = User::create([
                'name' => $data['name'],
                'email' => $data['email'],
                'phone' => $data['phone'] ?? null,
                'password' => $data['password'],
                'role' => $role,
                'is_active' => true,
            ]);

            if ($role === UserRole::Negocio) {
                $business = $data['business'];
                $business['slug'] = $this->uniqueSlug($business['name']);
                $business['card_settings'] = config('punto_plus.cards.default_settings', []);

                $user->business()->create($business);
            }

            return $user;
        });

        $user->load('business');

        try {
            $token = $this->issueToken(
                email: $data['email'],
                password: $data['password'],
                user: $user,
                requestedScopes: $data['scopes'] ?? null,
                deviceName: $data['device_name'] ?? null,
            );
        } catch (OAuthConfigurationException $e) {
            return $this->configurationErrorResponse($e);
        }

        return response()->json([
            'message' => 'Registro completado correctamente.',
            'user' => new UserResource($user),
            'authorization' => $token->toArray(),
        ], 201);
    }

    /**
     * POST /api/auth/login
     */
    public function login(LoginRequest $request): JsonResponse
    {
        $data = $request->validated();

        $user = User::with('business')->where('email', $data['email'])->first();

        if ($user !== null && ! $user->is_active) {
            return response()->json([
                'message' => 'Tu cuenta está desactivada. Contacta con soporte.',
                'error' => 'account_disabled',
            ], 403);
        }

        try {
            $token = $this->issueToken(
                email: $data['email'],
                password: $data['password'],
                user: $user,
                requestedScopes: $data['scopes'] ?? null,
                deviceName: $data['device_name'] ?? null,
            );
        } catch (OAuthServerException|InvalidCredentialsException) {
            return $this->invalidCredentialsResponse();
        } catch (OAuthConfigurationException $e) {
            return $this->configurationErrorResponse($e);
        }

        $user?->forceFill(['last_login_at' => now()])->save();

        return response()->json([
            'message' => 'Sesión iniciada.',
            'user' => new UserResource($user),
            'authorization' => $token->toArray(),
        ]);
    }

    /**
     * POST /api/auth/refresh
     */
    public function refresh(RefreshTokenRequest $request): JsonResponse
    {
        $data = $request->validated();

        try {
            $token = $this->broker->refreshGrant($data['refresh_token'], $data['scope'] ?? null);
        } catch (OAuthServerException $e) {
            return response()->json([
                'message' => 'El refresh token no es válido o ha caducado. Vuelve a iniciar sesión.',
                'error' => 'invalid_refresh_token',
            ], 401);
        } catch (OAuthConfigurationException $e) {
            return $this->configurationErrorResponse($e);
        }

        return response()->json([
            'message' => 'Token renovado.',
            'authorization' => $token->toArray(),
        ]);
    }

    /**
     * POST /api/auth/logout — revoca el token actual (y su refresh token).
     */
    public function logout(Request $request): JsonResponse
    {
        $id = $this->currentTokenId($request);

        if ($id === null) {
            return response()->json([
                'message' => 'No había ninguna sesión activa que cerrar.',
                'revoked_refresh_tokens' => 0,
            ]);
        }

        $refreshTokens = Passport::refreshToken()
            ->newQuery()
            ->where('access_token_id', $id)
            ->update(['revoked' => true]);

        Passport::token()->newQuery()->whereKey($id)->update(['revoked' => true]);

        return response()->json([
            'message' => 'Sesión cerrada.',
            'revoked_refresh_tokens' => $refreshTokens,
        ]);
    }

    /**
     * POST /api/auth/logout-all — revoca todos los tokens del usuario.
     */
    public function logoutAll(Request $request): JsonResponse
    {
        $user = $request->user();

        $tokenIds = Passport::token()
            ->newQuery()
            ->where('user_id', $user->getAuthIdentifier())
            ->where('revoked', false)
            ->pluck('id');

        Passport::refreshToken()
            ->newQuery()
            ->whereIn('access_token_id', $tokenIds)
            ->update(['revoked' => true]);

        Passport::token()
            ->newQuery()
            ->whereIn('id', $tokenIds)
            ->update(['revoked' => true]);

        return response()->json([
            'message' => 'Se cerraron todas las sesiones.',
            'revoked_tokens' => $tokenIds->count(),
        ]);
    }

    // -----------------------------------------------------------------
    // Internals
    // -----------------------------------------------------------------

    /**
     * Emite el token del usuario: password grant si hay cliente OAuth2
     * configurado; si no, token personal (modo degradado documentado).
     *
     * @param  array<int, string>|null  $requestedScopes
     */
    private function issueToken(
        string $email,
        string $password,
        ?User $user,
        ?array $requestedScopes = null,
        ?string $deviceName = null,
    ): TokenResponse {
        $scopes = $user?->oauthScopes($requestedScopes);

        if ($this->broker->hasPasswordClient()) {
            return $this->broker->passwordGrant($email, $password, $scopes, $deviceName);
        }

        // Modo degradado: sin cliente password grant no hay refresh token.
        // Se documenta en el README y se avisa en el log para el operador.
        Log::warning('Login sin cliente OAuth2 password grant: se emite token personal.', [
            'email' => $email,
        ]);

        if ($user === null || ! Hash::check($password, $user->password)) {
            throw new InvalidCredentialsException;
        }

        return $this->broker->personalAccessToken($user, $deviceName, $scopes);
    }

    private function invalidCredentialsResponse(): JsonResponse
    {
        return response()->json([
            'message' => 'Las credenciales no son correctas.',
            'error' => 'invalid_credentials',
        ], 401);
    }

    private function configurationErrorResponse(Throwable $e): JsonResponse
    {
        report($e);

        return response()->json([
            'message' => 'El servidor OAuth2 no está configurado correctamente.',
            'error' => 'oauth_not_configured',
            'hint' => $e->getMessage(),
        ], 503);
    }

    private function uniqueSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'negocio';
        $slug = $base;
        $suffix = 1;

        while (\App\Models\Business::withTrashed()->where('slug', $slug)->exists()) {
            $slug = $base.'-'.(++$suffix);
        }

        return $slug;
    }
}
