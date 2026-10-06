<?php

namespace App\Models;

use App\Support\Media;
use Database\Factories\BusinessFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Negocio (pequeña empresa) que ofrece programas de fidelidad.
 */
#[Fillable([
    'user_id', 'name', 'slug', 'description', 'category', 'phone', 'email', 'website',
    'address', 'city', 'country', 'latitude', 'longitude',
    'logo_path', 'background_path', 'stamp_icon_path', 'card_settings', 'is_active',
])]
class Business extends Model
{
    /** @use HasFactory<BusinessFactory> */
    use HasFactory, HasUuids, SoftDeletes;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'card_settings' => 'array',
            'is_active' => 'boolean',
            'latitude' => 'float',
            'longitude' => 'float',
        ];
    }

    // -----------------------------------------------------------------
    // Relaciones
    // -----------------------------------------------------------------

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function loyaltyCards(): HasMany
    {
        return $this->hasMany(LoyaltyCard::class);
    }

    public function customerCards(): HasMany
    {
        return $this->hasMany(CustomerCard::class);
    }

    public function stamps(): HasMany
    {
        return $this->hasMany(Stamp::class);
    }

    public function promotions(): HasMany
    {
        return $this->hasMany(Promotion::class);
    }

    public function rewards(): HasMany
    {
        return $this->hasMany(Reward::class);
    }

    // -----------------------------------------------------------------
    // Scopes
    // -----------------------------------------------------------------

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeSearch(Builder $query, ?string $term): Builder
    {
        if (blank($term)) {
            return $query;
        }

        return $query->where(function (Builder $query) use ($term): void {
            $like = '%'.$term.'%';

            $query->where('name', 'like', $like)
                ->orWhere('description', 'like', $like)
                ->orWhere('category', 'like', $like);
        });
    }

    public function scopeInCity(Builder $query, ?string $city): Builder
    {
        return $query->when($city, fn (Builder $q) => $q->where('city', $city));
    }

    public function scopeInCategory(Builder $query, ?string $category): Builder
    {
        return $query->when($category, fn (Builder $q) => $q->where('category', $category));
    }

    // -----------------------------------------------------------------
    // Accesores
    // -----------------------------------------------------------------

    protected function logoUrl(): Attribute
    {
        return Attribute::get(fn (): ?string => Media::url($this->logo_path));
    }

    protected function backgroundUrl(): Attribute
    {
        return Attribute::get(fn (): ?string => Media::url($this->background_path));
    }

    protected function stampIconUrl(): Attribute
    {
        return Attribute::get(fn (): ?string => Media::url($this->stamp_icon_path));
    }

    /**
     * Valores por defecto de la tarjeta, usados al crear nuevas tarjetas.
     *
     * @return array<string, mixed>
     */
    public function defaultCardSettings(): array
    {
        return array_merge(
            config('punto_plus.cards.default_settings'),
            ['required_stamps' => config('punto_plus.cards.default_required_stamps')],
            $this->card_settings ?? [],
        );
    }

    public function isOwnedBy(?User $user): bool
    {
        return $user !== null && $this->user_id === $user->getKey();
    }
}
