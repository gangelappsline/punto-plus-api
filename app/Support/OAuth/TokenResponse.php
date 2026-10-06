<?php

namespace App\Support\OAuth;

/**
 * Respuesta del endpoint OAuth2 /oauth/token, normalizada para la API.
 */
final readonly class TokenResponse
{
    /**
     * @param  array<int, string>  $scopes
     */
    public function __construct(
        public string $accessToken,
        public ?string $refreshToken,
        public string $tokenType,
        public int $expiresIn,
        public array $scopes = [],
    ) {
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            accessToken: (string) ($data['access_token'] ?? ''),
            refreshToken: isset($data['refresh_token']) ? (string) $data['refresh_token'] : null,
            tokenType: (string) ($data['token_type'] ?? 'Bearer'),
            expiresIn: (int) ($data['expires_in'] ?? 0),
            scopes: is_string($data['scope'] ?? null)
                ? array_values(array_filter(explode(' ', $data['scope'])))
                : (array) ($data['scopes'] ?? []),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'access_token' => $this->accessToken,
            'refresh_token' => $this->refreshToken,
            'token_type' => $this->tokenType,
            'expires_in' => $this->expiresIn,
            'scope' => implode(' ', $this->scopes),
        ];
    }
}
