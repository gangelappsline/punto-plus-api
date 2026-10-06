<?php

namespace App\Models;

use App\Enums\DiscountType;
use App\Support\Media;
use Database\Factories\PromotionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Promoción publicada por un negocio (puede estar ligada a una tarjeta).
 */
#[Fillable([
    'business_id', 'loyalty_card_id', 'title', 'description', 'image_path', 'terms',
    'discount_type', 'discount_value', 'code', 'starts_at', 'ends_at',
    'is_active', 'max_redemptions', 'redemptions_count',
])]
class Promotion extends Model
{
    /** @use HasFactory<PromotionFactory> */
    use HasFactory, HasUuids, SoftDeletes;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'discount_type' => DiscountType::class,
            'discount_value' => 'decimal:2',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'is_active' => 'boolean',
            'max_redemptions' => 'integer',
            'redemptions_count' => 'integer',
        ];
    }

    // -----------------------------------------------------------------
    // Relaciones
    // -----------------------------------------------------------------

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function loyaltyCard(): BelongsTo
    {
        return $this->belongsTo(LoyaltyCard::class);
    }

    // -----------------------------------------------------------------
    // Scopes
    // -----------------------------------------------------------------

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true)
            ->where(fn (Builder $q) => $q->whereNull('starts_at')->orWhere('starts_at', '<=', now()))
            ->where(fn (Builder $q) => $q->whereNull('ends_at')->orWhere('ends_at', '>=', now()));
    }

    public function scopeForBusiness(Builder $query, Business|string $business): Builder
    {
        return $query->where('business_id', $business instanceof Business ? $business->getKey() : $business);
    }

    // -----------------------------------------------------------------
    // Accesores y helpers
    // -----------------------------------------------------------------

    protected function imageUrl(): Attribute
    {
        return Attribute::get(fn (): ?string => Media::url($this->image_path));
    }

    public function isActiveNow(): bool
    {
        return $this->is_active
            && ($this->starts_at === null || $this->starts_at->isPast())
            && ($this->ends_at === null || $this->ends_at->isFuture());
    }

    public function hasReachedLimit(): bool
    {
        return $this->max_redemptions !== null
            && $this->redemptions_count >= $this->max_redemptions;
    }
}
