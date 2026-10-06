<?php

namespace App\Models;

use App\Enums\RewardType;
use App\Support\Media;
use Database\Factories\RewardFactory;
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
 * Recompensa que el cliente obtiene al completar (o alcanzar un hito de) la tarjeta.
 */
#[Fillable([
    'business_id', 'loyalty_card_id', 'name', 'description', 'image_path',
    'reward_type', 'value', 'required_stamps', 'stock', 'is_active',
])]
class Reward extends Model
{
    /** @use HasFactory<RewardFactory> */
    use HasFactory, HasUuids, SoftDeletes;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'reward_type' => RewardType::class,
            'value' => 'decimal:2',
            'required_stamps' => 'integer',
            'stock' => 'integer',
            'is_active' => 'boolean',
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

    public function redemptions(): HasMany
    {
        return $this->hasMany(Redemption::class);
    }

    // -----------------------------------------------------------------
    // Scopes
    // -----------------------------------------------------------------

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeInStock(Builder $query): Builder
    {
        return $query->where(fn (Builder $q) => $q->whereNull('stock')->orWhere('stock', '>', 0));
    }

    public function scopeForCard(Builder $query, LoyaltyCard|string $card): Builder
    {
        return $query->where('loyalty_card_id', $card instanceof LoyaltyCard ? $card->getKey() : $card);
    }

    // -----------------------------------------------------------------
    // Accesores y helpers
    // -----------------------------------------------------------------

    protected function imageUrl(): Attribute
    {
        return Attribute::get(fn (): ?string => Media::url($this->image_path));
    }

    public function hasStock(): bool
    {
        return $this->stock === null || $this->stock > 0;
    }
}
