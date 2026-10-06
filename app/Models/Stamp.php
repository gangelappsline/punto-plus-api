<?php

namespace App\Models;

use App\Enums\StampSource;
use Database\Factories\StampFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Sello (compra escaneada) sobre la tarjeta de un cliente.
 */
#[Fillable([
    'customer_card_id', 'business_id', 'loyalty_card_id', 'registered_by',
    'source', 'purchase_amount', 'notes', 'stamped_at', 'meta',
])]
class Stamp extends Model
{
    /** @use HasFactory<StampFactory> */
    use HasFactory, HasUuids;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'source' => StampSource::class,
            'purchase_amount' => 'decimal:2',
            'stamped_at' => 'datetime',
            'meta' => 'array',
        ];
    }

    // -----------------------------------------------------------------
    // Relaciones
    // -----------------------------------------------------------------

    public function customerCard(): BelongsTo
    {
        return $this->belongsTo(CustomerCard::class);
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function loyaltyCard(): BelongsTo
    {
        return $this->belongsTo(LoyaltyCard::class);
    }

    public function registeredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'registered_by');
    }

    // -----------------------------------------------------------------
    // Scopes
    // -----------------------------------------------------------------

    public function scopeForBusiness(Builder $query, Business|string $business): Builder
    {
        return $query->where('business_id', $business instanceof Business ? $business->getKey() : $business);
    }

    public function scopeForCard(Builder $query, CustomerCard|string $card): Builder
    {
        return $query->where('customer_card_id', $card instanceof CustomerCard ? $card->getKey() : $card);
    }

    public function scopeBetween(Builder $query, mixed $from, mixed $to): Builder
    {
        return $query->when($from, fn (Builder $q) => $q->where('stamped_at', '>=', $from))
            ->when($to, fn (Builder $q) => $q->where('stamped_at', '<=', $to));
    }

    public function scopeRecent(Builder $query): Builder
    {
        return $query->latest('stamped_at');
    }
}
