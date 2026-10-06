<?php

namespace App\Models;

use App\Support\Media;
use Database\Factories\LoyaltyCardFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

/**
 * Tarjeta de fidelidad (programa de sellos) definida por un negocio.
 *
 * El cliente se une al programa escaneando un QR que contiene `join_code`.
 */
#[Fillable([
    'business_id', 'name', 'slug', 'description', 'required_stamps', 'reward_description', 'terms',
    'logo_path', 'background_path', 'stamp_icon_path',
    'primary_color', 'secondary_color', 'text_color',
    'join_code', 'is_active', 'is_public', 'expires_at', 'settings',
])]
class LoyaltyCard extends Model
{
    /** @use HasFactory<LoyaltyCardFactory> */
    use HasFactory, HasUuids, SoftDeletes;

    protected static function booted(): void
    {
        static::creating(function (self $card): void {
            $card->join_code ??= self::generateJoinCode();

            if (blank($card->slug)) {
                $card->slug = Str::slug($card->name).'-'.Str::lower(Str::random(5));
            }
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'required_stamps' => 'integer',
            'is_active' => 'boolean',
            'is_public' => 'boolean',
            'expires_at' => 'datetime',
            'settings' => 'array',
        ];
    }

    public static function generateJoinCode(int $length = 8): string
    {
        do {
            $code = Str::upper(Str::random($length));
        } while (self::withTrashed()->where('join_code', $code)->exists());

        return $code;
    }

    // -----------------------------------------------------------------
    // Relaciones
    // -----------------------------------------------------------------

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function customerCards(): HasMany
    {
        return $this->hasMany(CustomerCard::class);
    }

    public function stamps(): HasMany
    {
        return $this->hasMany(Stamp::class);
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
        return $query->where('is_active', true)
            ->where(fn (Builder $q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()));
    }

    public function scopeForBusiness(Builder $query, Business|string $business): Builder
    {
        return $query->where('business_id', $business instanceof Business ? $business->getKey() : $business);
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
     * Enlace que la app móvil usa como fallback si no puede leer el QR.
     */
    protected function joinUrl(): Attribute
    {
        return Attribute::get(fn (): string => url('/join/'.$this->join_code));
    }

    // -----------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------

    public function isActiveNow(): bool
    {
        return $this->is_active
            && $this->trashed() === false
            && ($this->expires_at === null || $this->expires_at->isFuture());
    }

    /**
     * Recompensa que se alcanza con el número de sellos indicado.
     */
    public function rewardFor(int $stamps): ?Reward
    {
        return $this->rewards()
            ->where('is_active', true)
            ->where('required_stamps', '<=', $stamps)
            ->orderByDesc('required_stamps')
            ->first();
    }
}
