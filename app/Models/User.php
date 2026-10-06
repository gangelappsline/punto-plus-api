<?php

namespace App\Models;

use App\Enums\UserRole;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Passport\Contracts\OAuthenticatable;
use Laravel\Passport\HasApiTokens;

/**
 * Usuario de la plataforma. Un mismo registro puede ser cliente, negocio o
 * administrador de Punto Plus (campo `role`).
 */
#[Fillable(['name', 'email', 'phone', 'password', 'role', 'is_active'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable implements OAuthenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'last_login_at' => 'datetime',
            'password' => 'hashed',
            'role' => UserRole::class,
            'is_active' => 'boolean',
        ];
    }

    // -----------------------------------------------------------------
    // Relaciones
    // -----------------------------------------------------------------

    /**
     * Perfil de negocio (sólo si el usuario tiene rol `negocio`).
     */
    public function business(): HasOne
    {
        return $this->hasOne(Business::class);
    }

    /**
     * Tarjetas de fidelidad que el usuario ha empezado como cliente.
     */
    public function customerCards(): HasMany
    {
        return $this->hasMany(CustomerCard::class);
    }

    /**
     * Canjes solicitados por el usuario (como cliente).
     */
    public function redemptions(): HasMany
    {
        return $this->hasMany(Redemption::class);
    }

    /**
     * Sellos registrados por el usuario cuando actúa como personal del negocio.
     */
    public function registeredStamps(): HasMany
    {
        return $this->hasMany(Stamp::class, 'registered_by');
    }

    // -----------------------------------------------------------------
    // Scopes
    // -----------------------------------------------------------------

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeOfRole(Builder $query, UserRole $role): Builder
    {
        return $query->where('role', $role->value);
    }

    // -----------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------

    public function isCliente(): bool
    {
        return $this->role === UserRole::Cliente;
    }

    public function isNegocio(): bool
    {
        return $this->role === UserRole::Negocio;
    }

    public function isAdmin(): bool
    {
        return $this->role === UserRole::Admin;
    }

    public function hasRole(UserRole|string ...$roles): bool
    {
        $allowed = array_map(
            fn (UserRole|string $role): string => $role instanceof UserRole ? $role->value : $role,
            $roles,
        );

        return in_array($this->role->value, $allowed, true);
    }

    /**
     * ¿El usuario es propietario del negocio indicado?
     */
    public function ownsBusiness(?Business $business): bool
    {
        return $business !== null && $business->user_id === $this->getKey();
    }

    /**
     * Scopes OAuth2 que se conceden al emitir un token para este usuario.
     *
     * @return array<int, string>
     */
    public function oauthScopes(?array $requested = null): array
    {
        $granted = [$this->role->scope()];

        return $requested === null
            ? $granted
            : array_values(array_intersect($granted, $requested));
    }
}
