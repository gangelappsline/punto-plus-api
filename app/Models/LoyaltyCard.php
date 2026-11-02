<?php

namespace App\Models;

use App\Enums\CardPeriodType;
use App\Enums\LoyaltyCardType;
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
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Tarjeta de fidelidad definida por un negocio.
 *
 * Existen dos tipos:
 *  - `visits`: el cliente acumula visitas y al llegar a `required_stamps`
 *    obtiene la recompensa del ciclo (con vigencia opcional `period_days`).
 *  - `spend`: cada `spend_amount_per_block` de consumo suma `points_per_block`
 *    puntos; las recompensas se desbloquean por umbrales (`rewards.points_required`).
 *
 * El cliente se une al programa escaneando un QR que contiene `join_code`.
 */
#[Fillable([
    'business_id', 'name', 'slug', 'description', 'type', 'required_stamps', 'reward_description', 'terms',
    'period_type', 'period_days', 'currency', 'spend_amount_per_block', 'points_per_block',
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
            'type' => LoyaltyCardType::class,
            'period_type' => CardPeriodType::class,
            'required_stamps' => 'integer',
            'period_days' => 'integer',
            'spend_amount_per_block' => 'decimal:2',
            'points_per_block' => 'integer',
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

    public function scopeOfType(Builder $query, LoyaltyCardType|string|null $type): Builder
    {
        if (blank($type)) {
            return $query;
        }

        return $query->where('type', $type instanceof LoyaltyCardType ? $type->value : $type);
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
    // Tipo de tarjeta
    // -----------------------------------------------------------------

    public function isVisits(): bool
    {
        return $this->type === LoyaltyCardType::Visits;
    }

    public function isSpend(): bool
    {
        return $this->type === LoyaltyCardType::Spend;
    }

    public function progressUnit(): string
    {
        return $this->type->progressUnit();
    }

    /**
     * Puntos que genera un consumo de `$amount` en esta tarjeta.
     */
    public function pointsForAmount(float $amount): int
    {
        $perBlock = (float) ($this->spend_amount_per_block ?? 0);
        $points = (int) ($this->points_per_block ?? 0);

        if ($perBlock <= 0 || $points <= 0 || $amount <= 0) {
            return 0;
        }

        return (int) floor($amount / $perBlock) * $points;
    }

    /**
     * ¿El ciclo caduca? Sólo cuando el negocio define un lapso de días.
     */
    public function hasPeriod(): bool
    {
        return $this->period_type === CardPeriodType::Days && (int) $this->period_days > 0;
    }

    /**
     * Fecha de caducidad del ciclo que empieza en `$start`.
     */
    public function cycleExpiresAtFrom(?Carbon $start = null): ?Carbon
    {
        if (! $this->hasPeriod()) {
            return null;
        }

        return ($start ?? now())->copy()->addDays((int) $this->period_days);
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
     * Recompensas activas ordenadas por umbral (visitas o puntos).
     *
     * @return \Illuminate\Support\Collection<int, Reward>
     */
    public function rewardTiers()
    {
        return $this->rewards()
            ->where('is_active', true)
            ->orderedByThreshold()
            ->get();
    }

    /**
     * Recompensa alcanzada con el número de visitas/puntos indicado.
     */
    public function rewardFor(int $value): ?Reward
    {
        $query = $this->rewards()->where('is_active', true);

        if ($this->isSpend()) {
            return $query->whereNotNull('points_required')
                ->where('points_required', '<=', $value)
                ->orderByDesc('points_required')
                ->first();
        }

        return $query->whereNotNull('required_stamps')
            ->where('required_stamps', '<=', $value)
            ->orderByDesc('required_stamps')
            ->first();
    }

    /**
     * Umbral de la siguiente recompensa por encima de `$value`.
     */
    public function nextRewardFor(int $value): ?Reward
    {
        $query = $this->rewards()->where('is_active', true);

        if ($this->isSpend()) {
            return $query->whereNotNull('points_required')
                ->where('points_required', '>', $value)
                ->orderBy('points_required')
                ->first();
        }

        return $query->whereNotNull('required_stamps')
            ->where('required_stamps', '>', $value)
            ->orderBy('required_stamps')
            ->first();
    }
}
