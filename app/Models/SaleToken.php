<?php

namespace App\Models;

use Database\Factories\SaleTokenFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Token de venta de un negocio.
 *
 * El panel del negocio lo genera (`GET /api/business/new-token`) y lo muestra
 * como QR o como texto; el cliente lo valida (`POST /api/customer/validate-token`)
 * y la compra se registra en su tarjeta. Dura `SALE_TOKEN_TTL_MINUTES` (5 por
 * defecto) y es de **un solo uso**: al validarse se elimina.
 */
#[Fillable([
    'business_id', 'loyalty_card_id', 'created_by', 'code',
    'purchase_amount', 'notes', 'expires_at',
])]
class SaleToken extends Model
{
    /** @use HasFactory<SaleTokenFactory> */
    use HasFactory, HasUuids, MassPrunable;

    /**
     * Alfabeto sin caracteres ambiguos (sin O/0 ni I/1/L) para dictarlo en voz alta.
     */
    public const ALPHABET = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';

    protected static function booted(): void
    {
        static::creating(function (self $token): void {
            $token->code ??= self::generateCode();
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'purchase_amount' => 'decimal:2',
            'expires_at' => 'datetime',
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

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    // -----------------------------------------------------------------
    // Scopes
    // -----------------------------------------------------------------

    public function scopeForBusiness(Builder $query, Business|string $business): Builder
    {
        return $query->where('business_id', $business instanceof Business ? $business->getKey() : $business);
    }

    /**
     * Tokens que todavía se pueden validar.
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('expires_at', '>', now());
    }

    /**
     * Tokens caducados: candidatos a `model:prune`.
     */
    public function prunable(): Builder
    {
        return static::query()->where('expires_at', '<', now()->subDay());
    }

    // -----------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------

    public function isExpired(): bool
    {
        return $this->expires_at === null || $this->expires_at->getTimestamp() <= now()->getTimestamp();
    }

    /**
     * Segundos que le quedan de vida (0 si ya caducó).
     */
    public function expiresInSeconds(): int
    {
        if ($this->expires_at === null) {
            return 0;
        }

        return max(0, $this->expires_at->getTimestamp() - now()->getTimestamp());
    }

    /**
     * Minutos de vigencia configurados (para los mensajes de error).
     */
    public function ttlMinutes(): int
    {
        return max(1, (int) config('punto_plus.sale_tokens.ttl_minutes', 5));
    }

    /**
     * Código agrupado para leerlo/dictarlo: `ABCD-EFGH`.
     */
    protected function displayCode(): Attribute
    {
        return Attribute::get(function (): string {
            $code = (string) $this->code;

            return strlen($code) > 4
                ? substr($code, 0, 4).'-'.substr($code, 4)
                : $code;
        });
    }

    /**
     * Normaliza lo que teclea el cliente: sin espacios ni guiones, en mayúsculas.
     */
    public static function normalizeCode(?string $raw): string
    {
        return mb_strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', (string) $raw));
    }

    public static function generateCode(?int $length = null): string
    {
        $length = max(4, (int) ($length ?? config('punto_plus.sale_tokens.code_length', 8)));
        $alphabet = self::ALPHABET;
        $max = strlen($alphabet) - 1;

        do {
            $code = '';

            for ($i = 0; $i < $length; $i++) {
                $code .= $alphabet[random_int(0, $max)];
            }
        } while (self::query()->where('code', $code)->exists());

        return $code;
    }
}
