<?php

namespace App\Models;

use App\Enums\RedemptionStatus;
use Database\Factories\RedemptionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * Canje de una recompensa. Nace en estado `pending` (el cliente lo solicita)
 * y el negocio lo valida con `code` para entregar el premio.
 */
#[Fillable([
    'customer_card_id', 'reward_id', 'business_id', 'user_id', 'code', 'status',
    'stamps_used', 'redeemed_at', 'approved_by', 'approved_at', 'notes',
])]
class Redemption extends Model
{
    /** @use HasFactory<RedemptionFactory> */
    use HasFactory, HasUuids, MassPrunable;

    protected static function booted(): void
    {
        static::creating(function (self $redemption): void {
            $redemption->code ??= self::generateCode();
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => RedemptionStatus::class,
            'stamps_used' => 'integer',
            'redeemed_at' => 'datetime',
            'approved_at' => 'datetime',
        ];
    }

    public static function generateCode(int $length = 8): string
    {
        do {
            $code = 'PP-'.Str::upper(Str::random($length));
        } while (self::where('code', $code)->exists());

        return $code;
    }

    // -----------------------------------------------------------------
    // Relaciones
    // -----------------------------------------------------------------

    public function customerCard(): BelongsTo
    {
        return $this->belongsTo(CustomerCard::class);
    }

    public function reward(): BelongsTo
    {
        return $this->belongsTo(Reward::class);
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    // -----------------------------------------------------------------
    // Scopes
    // -----------------------------------------------------------------

    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', RedemptionStatus::Pending->value);
    }

    public function scopeForBusiness(Builder $query, Business|string $business): Builder
    {
        return $query->where('business_id', $business instanceof Business ? $business->getKey() : $business);
    }

    public function scopeForUser(Builder $query, User|string $user): Builder
    {
        return $query->where('user_id', $user instanceof User ? $user->getKey() : $user);
    }

    /**
     * Canjes cerrados hace más de 6 meses: candidatos a `model:prune`.
     */
    public function prunable(): Builder
    {
        return static::query()
            ->whereIn('status', [
                RedemptionStatus::Completed->value,
                RedemptionStatus::Rejected->value,
                RedemptionStatus::Cancelled->value,
            ])
            ->where('updated_at', '<', now()->subMonths(6));
    }

    // -----------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------

    public function isPending(): bool
    {
        return $this->status === RedemptionStatus::Pending;
    }
}
