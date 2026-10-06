<?php

namespace App\Models;

use App\Enums\CardStatus;
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
use Illuminate\Support\Str;

/**
 * Tarjeta de un cliente dentro de un programa de fidelidad.
 *
 * `code` es el identificador que viaja en el QR del cliente y que el negocio
 * escanea (análogo al número de tarjeta física).
 */
#[Fillable([
    'user_id', 'loyalty_card_id', 'business_id', 'code',
    'stamps_count', 'total_stamps_earned', 'rewards_earned', 'rewards_redeemed',
    'status', 'completed_at', 'last_stamp_at', 'expires_at',
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
            'rewards_earned' => 'integer',
            'rewards_redeemed' => 'integer',
            'status' => CardStatus::class,
            'completed_at' => 'datetime',
            'last_stamp_at' => 'datetime',
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
    // Accesores de progreso (los expone CustomerCardResource)
    // -----------------------------------------------------------------

    protected function requiredStamps(): Attribute
    {
        return Attribute::get(fn (): int => (int) ($this->loyaltyCard?->required_stamps
            ?? config('punto_plus.cards.default_required_stamps', 10)));
    }

    protected function stampsRemaining(): Attribute
    {
        return Attribute::get(fn (): int => max(0, $this->required_stamps - $this->stamps_count));
    }

    protected function progressPercentage(): Attribute
    {
        return Attribute::get(function (): float {
            $required = max(1, $this->required_stamps);

            return (float) min(100, round(($this->stamps_count / $required) * 100, 2));
        });
    }

    /**
     * @return array<string, mixed>
     */
    protected function progress(): Attribute
    {
        return Attribute::get(fn (): array => [
            'stamps_count' => $this->stamps_count,
            'required_stamps' => $this->required_stamps,
            'stamps_remaining' => $this->stamps_remaining,
            'progress_percentage' => $this->progress_percentage,
            'completed' => $this->isCompleted(),
        ]);
    }

    // -----------------------------------------------------------------
    // Helpers de estado
    // -----------------------------------------------------------------

    public function isCompleted(): bool
    {
        return $this->status === CardStatus::Completed
            || $this->stamps_count >= $this->required_stamps;
    }

    public function isUsable(): bool
    {
        if (! $this->status->isUsable()) {
            return false;
        }

        if ($this->expires_at !== null && $this->expires_at->isPast()) {
            return false;
        }

        return (bool) $this->loyaltyCard?->isActiveNow();
    }

    /**
     * Días que siguen siendo válidos los sellos acumulados (null = no caducan).
     *
     * Manda la configuración de la tarjeta del negocio y, si no la define, la global
     * (`punto_plus.cards.stamps_lifetime_days`). Lo aplica el comando
     * `punto-plus:expire-cards`.
     */
    public function stampLifetimeDays(): ?int
    {
        $settings = $this->loyaltyCard?->settings ?? [];
        $days = $settings['stamps_lifetime_days'] ?? config('punto_plus.cards.stamps_lifetime_days');

        return $days === null || $days === '' ? null : (int) $days;
    }

    /**
     * ¿El cliente ya puede canjear esta recompensa?
     */
    public function meetsRequirementFor(Reward $reward): bool
    {
        return $this->stamps_count >= (int) $reward->required_stamps;
    }
}
