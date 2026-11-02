<?php

namespace App\Models;

use App\Enums\LoyaltyCardType;
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
 * Recompensa de una tarjeta de fidelidad.
 *
 * El umbral se expresa en la unidad del tipo de tarjeta:
 *  - tarjetas por visitas → `required_stamps` (visitas necesarias);
 *  - tarjetas por consumo → `points_required` (puntos necesarios).
 *
 * Una tarjeta por consumo puede tener varios niveles (p. ej. 200 puntos → refresco,
 * 500 puntos → hamburguesa).
 */
#[Fillable([
    'business_id', 'loyalty_card_id', 'name', 'description', 'image_path',
    'reward_type', 'value', 'required_stamps', 'points_required',
    'stock', 'redemptions_count', 'is_active',
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
            'points_required' => 'integer',
            'stock' => 'integer',
            'redemptions_count' => 'integer',
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

    /**
     * Ordena por el umbral vigente, sea de visitas o de puntos.
     */
    public function scopeOrderedByThreshold(Builder $query): Builder
    {
        return $query->orderByRaw('COALESCE(required_stamps, points_required) asc');
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

    /**
     * Umbral que aplica a un tipo de tarjeta.
     */
    public function thresholdFor(LoyaltyCardType|string|null $type): ?int
    {
        $type = $type instanceof LoyaltyCardType ? $type : (LoyaltyCardType::tryFrom((string) $type) ?? LoyaltyCardType::Visits);

        $value = $type === LoyaltyCardType::Spend ? $this->points_required : $this->required_stamps;

        return $value === null ? null : (int) $value;
    }

    /**
     * Unidad del umbral ('visits' o 'points').
     */
    public function thresholdUnit(): string
    {
        return $this->points_required !== null ? 'points' : 'visits';
    }
}
