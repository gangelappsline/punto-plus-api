<?php

namespace App\Models;

use App\Enums\CardStatus;
use App\Enums\LoyaltyCardType;
use Database\Factories\CustomerCardFactory;
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
 * Tarjeta de un cliente dentro de un programa de fidelidad.
 *
 * `code` es el identificador que viaja en el QR del cliente y que el negocio
 * escanea (análogo al número de tarjeta física).
 *
 * El progreso se mide en visitas (`stamps_count`) o en puntos (`points_count`)
 * según el tipo de la tarjeta del negocio.
 */
#[Fillable([
    'user_id', 'loyalty_card_id', 'business_id', 'code',
    'stamps_count', 'total_stamps_earned', 'points_count', 'total_points_earned',
    'rewards_earned', 'rewards_redeemed',
    'status', 'completed_at', 'last_stamp_at', 'cycle_started_at', 'cycle_expires_at', 'expires_at',
])]
class CustomerCard extends Model
{
    /** @use HasFactory<CustomerCardFactory> */
    use HasFactory, HasUuids, SoftDeletes;

    /**
     * El progreso siempre necesita la tarjeta del negocio: se carga de forma
     * anticipada para evitar el problema N+1 en los listados.
     *
     * @var array<int, string>
     */
    protected $with = ['loyaltyCard'];

    protected static function booted(): void
    {
        static::creating(function (self $card): void {
            $card->code ??= self::generateCode();
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'stamps_count' => 'integer',
            'total_stamps_earned' => 'integer',
            'points_count' => 'integer',
            'total_points_earned' => 'integer',
            'rewards_earned' => 'integer',
            'rewards_redeemed' => 'integer',
            'status' => CardStatus::class,
            'completed_at' => 'datetime',
            'last_stamp_at' => 'datetime',
            'cycle_started_at' => 'datetime',
            'cycle_expires_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    public static function generateCode(int $length = 10): string
    {
        do {
            $code = Str::upper(Str::random($length));
        } while (self::where('code', $code)->exists());

        return $code;
    }

    // -----------------------------------------------------------------
    // Relaciones
    // -----------------------------------------------------------------

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function loyaltyCard(): BelongsTo
    {
        return $this->belongsTo(LoyaltyCard::class);
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function stamps(): HasMany
    {
        return $this->hasMany(Stamp::class)->latest('stamped_at');
    }

    public function redemptions(): HasMany
    {
        return $this->hasMany(Redemption::class)->latest();
    }

    // -----------------------------------------------------------------
    // Scopes
    // -----------------------------------------------------------------

    public function scopeActive(Builder $query): Builder
    {
        return $query->whereIn('status', [
            CardStatus::Active->value,
            CardStatus::Completed->value,
        ]);
    }

    public function scopeCompleted(Builder $query): Builder
    {
        return $query->where('status', CardStatus::Completed->value);
    }

    public function scopeForUser(Builder $query, User|string $user): Builder
    {
        return $query->where('user_id', $user instanceof User ? $user->getKey() : $user);
    }

    public function scopeForBusiness(Builder $query, Business|string $business): Builder
    {
        return $query->where('business_id', $business instanceof Business ? $business->getKey() : $business);
    }

    // -----------------------------------------------------------------
    // Tipo de programa
    // -----------------------------------------------------------------

    public function isSpend(): bool
    {
        return $this->loyaltyCard?->type === LoyaltyCardType::Spend;
    }

    public function progressUnit(): string
    {
        return $this->isSpend() ? 'points' : 'visits';
    }

    /**
     * Unidades acumuladas en la tarjeta (visitas o puntos).
     */
    public function currentUnits(): int
    {
        return $this->isSpend() ? (int) $this->points_count : (int) $this->stamps_count;
    }

    // -----------------------------------------------------------------
    // Accesores de progreso (los expone CustomerCardResource)
    // -----------------------------------------------------------------

    /**
     * Visitas requeridas. En las tarjetas por consumo no aplica (0).
     */
    protected function requiredStamps(): Attribute
    {
        return Attribute::get(function (): int {
            if ($this->isSpend()) {
                return 0;
            }

            return (int) ($this->loyaltyCard?->required_stamps
                ?? config('punto_plus.cards.default_required_stamps', 10));
        });
    }

    protected function stampsRemaining(): Attribute
    {
        return Attribute::get(fn (): int => $this->isSpend()
            ? 0
            : max(0, $this->required_stamps - (int) $this->stamps_count));
    }

    /**
     * Siguiente recompensa por alcanzar (o null si ya no hay más niveles).
     */
    protected function nextReward(): Attribute
    {
        return Attribute::get(fn (): ?Reward => $this->loyaltyCard?->nextRewardFor($this->currentUnits()));
    }

    protected function unitsToNextReward(): Attribute
    {
        return Attribute::get(function (): ?int {
            $threshold = $this->next_reward?->thresholdFor($this->loyaltyCard?->type);

            return $threshold === null ? null : max(0, $threshold - $this->currentUnits());
        });
    }

    protected function progressPercentage(): Attribute
    {
        return Attribute::get(function (): float {
            $current = $this->currentUnits();

            if ($this->isSpend()) {
                $threshold = $this->next_reward?->thresholdFor($this->loyaltyCard?->type);

                if ($threshold === null) {
                    return 100.0;
                }

                // Porcentaje dentro del tramo hasta la siguiente recompensa.
                return (float) min(100, round(($current / max(1, $threshold)) * 100, 2));
            }

            $required = max(1, $this->required_stamps);

            return (float) min(100, round(($current / $required) * 100, 2));
        });
    }

    /**
     * Recompensas que el cliente ya puede canjear (umbral alcanzado y con stock).
     *
     * @return \Illuminate\Support\Collection<int, Reward>
     */
    protected function availableRewards(): Attribute
    {
        return Attribute::get(function () {
            $card = $this->loyaltyCard;

            if ($card === null) {
                return collect();
            }

            return $card->rewards()
                ->where('is_active', true)
                ->inStock()
                ->orderedByThreshold()
                ->get()
                ->filter(fn (Reward $reward): bool => $this->meetsRequirementFor($reward))
                ->values();
        });
    }

    /**
     * @return array<string, mixed>
     */
    protected function progress(): Attribute
    {
        return Attribute::get(function (): array {
            $isSpend = $this->isSpend();
            $nextReward = $this->next_reward;
            $required = $isSpend
                ? $nextReward?->thresholdFor($this->loyaltyCard?->type)
                : $this->required_stamps;

            return [
                'unit' => $this->progressUnit(),
                'current' => $this->currentUnits(),
                'required' => $required,
                'remaining' => $isSpend
                    ? $this->units_to_next_reward
                    : $this->stamps_remaining,
                'percentage' => $this->progress_percentage,
                'completed' => $this->isCompleted(),

                // Puntos (sólo tarjetas por consumo).
                'points_count' => $isSpend ? (int) $this->points_count : null,
                'total_points_earned' => $isSpend ? (int) $this->total_points_earned : null,

                // Siguiente recompensa y recompensas disponibles.
                'next_reward' => $nextReward === null ? null : [
                    'id' => $nextReward->getKey(),
                    'name' => $nextReward->name,
                    'threshold' => $required,
                ],
                'available_rewards_count' => $this->available_rewards->count(),

                // Claves históricas (tarjetas por visitas).
                'stamps_count' => (int) $this->stamps_count,
                'required_stamps' => $this->required_stamps,
                'stamps_remaining' => $this->stamps_remaining,
                'progress_percentage' => $this->progress_percentage,
            ];
        });
    }

    /**
     * @return array<string, mixed>
     */
    protected function cycle(): Attribute
    {
        return Attribute::get(fn (): array => [
            'started_at' => $this->cycle_started_at?->toIso8601String(),
            'expires_at' => $this->cycle_expires_at?->toIso8601String(),
            'days' => $this->loyaltyCard?->hasPeriod() ? (int) $this->loyaltyCard->period_days : null,
            'expired' => $this->cycle_expires_at !== null && $this->cycle_expires_at->isPast(),
        ]);
    }

    // -----------------------------------------------------------------
    // Helpers de estado
    // -----------------------------------------------------------------

    /**
     * Las tarjetas por consumo nunca se "completan": acumulan puntos.
     */
    public function isCompleted(): bool
    {
        if ($this->isSpend()) {
            return false;
        }

        return $this->status === CardStatus::Completed
            || (int) $this->stamps_count >= $this->required_stamps;
    }

    public function isUsable(): bool
    {
        if (! $this->status->isUsable()) {
            return false;
        }

        if ($this->expires_at !== null && $this->expires_at->isPast()) {
            return false;
        }

        if ($this->cycle_expires_at !== null && $this->cycle_expires_at->isPast()) {
            return false;
        }

        return (bool) $this->loyaltyCard?->isActiveNow();
    }

    /**
     * Días que siguen siendo válidos los sellos acumulados (null = no caducan).
     *
     * Manda la vigencia del ciclo de la tarjeta (`period_days`) y, si no la define,
     * la configuración de la tarjeta (`settings.stamps_lifetime_days`) o la global
     * (`punto_plus.cards.stamps_lifetime_days`).
     */
    public function stampLifetimeDays(): ?int
    {
        $loyaltyCard = $this->loyaltyCard;

        if ($loyaltyCard?->hasPeriod()) {
            return (int) $loyaltyCard->period_days;
        }

        $settings = $loyaltyCard?->settings ?? [];
        $days = $settings['stamps_lifetime_days'] ?? config('punto_plus.cards.stamps_lifetime_days');

        return $days === null || $days === '' ? null : (int) $days;
    }

    /**
     * ¿El cliente alcanzó el umbral de la recompensa indicada?
     */
    public function meetsRequirementFor(Reward $reward): bool
    {
        $threshold = $reward->thresholdFor($this->loyaltyCard?->type ?? LoyaltyCardType::Visits);

        return $threshold !== null && $this->currentUnits() >= $threshold;
    }

    /**
     * Unidades (visitas o puntos) que le faltan para la recompensa.
     */
    public function missingUnitsFor(Reward $reward): int
    {
        $threshold = (int) ($reward->thresholdFor($this->loyaltyCard?->type ?? LoyaltyCardType::Visits) ?? 0);

        return max(0, $threshold - $this->currentUnits());
    }

    /**
     * Reinicia el ciclo (progreso a cero) y arranca uno nuevo si la tarjeta
     * define un lapso de días.
     */
    public function resetCycle(?Carbon $startedAt = null): self
    {
        $startedAt ??= now();

        $this->stamps_count = 0;
        $this->points_count = 0;
        $this->completed_at = null;
        $this->status = CardStatus::Active;
        $this->cycle_started_at = $startedAt;
        $this->cycle_expires_at = $this->loyaltyCard?->cycleExpiresAtFrom($startedAt);

        return $this;
    }

    /**
     * Marca el inicio del ciclo al darse de alta en el programa.
     */
    public function startCycle(?Carbon $startedAt = null): self
    {
        $startedAt ??= now();

        $this->cycle_started_at = $startedAt;
        $this->cycle_expires_at = $this->loyaltyCard?->cycleExpiresAtFrom($startedAt);

        return $this;
    }
}
