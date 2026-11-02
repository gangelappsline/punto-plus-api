<?php

namespace App\Services;

use App\Enums\StampSource;
use App\Models\Business;
use App\Models\CustomerCard;
use App\Models\LoyaltyCard;
use App\Models\SaleToken;
use App\Models\Stamp;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Tokens de venta.
 *
 * Flujo: el negocio pide un token desde su panel (`GET /api/business/new-token`),
 * lo muestra como QR o como código de texto y el cliente lo valida
 * (`POST /api/customer/validate-token`). Al validarlo la compra se registra en la
 * tarjeta del cliente (`stamps.source = token`) y el token se elimina.
 *
 * Reglas (config/punto_plus.php → `sale_tokens`):
 *  - dura `ttl_minutes` (5 por defecto) y es de un solo uso;
 *  - el negocio debe tener una tarjeta activa (o indicar `loyalty_card_id`);
 *  - el cliente se une al programa automáticamente si el programa es público;
 *  - el negocio no puede validar sus propios tokens (anti-fraude).
 */
final class SaleTokenService
{
    public function __construct(
        private readonly QrCodeService $qrCodes,
        private readonly StampService $stamps,
        private readonly CustomerCardService $cards,
    ) {
    }

    /**
     * Genera un token de venta para el negocio.
     *
     * @param  array<string, mixed>  $data
     */
    public function issue(Business $business, User $user, array $data = []): SaleToken
    {
        $loyaltyCard = $this->resolveLoyaltyCard($business, $data['loyalty_card_id'] ?? null);

        $this->assertBusinessCanSell($business, $loyaltyCard);

        $ttlMinutes = $this->ttlMinutes();

        /** @var SaleToken $token */
        $token = DB::transaction(function () use ($business, $user, $loyaltyCard, $data, $ttlMinutes): SaleToken {
            $this->removeExpired($business);
            $this->enforceActiveLimit($business);

            return SaleToken::create([
                'business_id' => $business->getKey(),
                'loyalty_card_id' => $loyaltyCard->getKey(),
                'created_by' => $user->getKey(),
                'purchase_amount' => $data['purchase_amount'] ?? null,
                'notes' => $data['notes'] ?? null,
                'expires_at' => now()->addMinutes($ttlMinutes),
            ]);
        });

        return $token->load(['loyaltyCard', 'business']);
    }

    /**
     * Tokens vivos del negocio (los que todavía se pueden validar).
     *
     * @return Collection<int, SaleToken>
     */
    public function activeFor(Business $business): Collection
    {
        return SaleToken::query()
            ->forBusiness($business)
            ->active()
            ->with('loyaltyCard')
            ->orderBy('expires_at')
            ->get();
    }

    /**
     * Valida un token y registra la venta en la tarjeta del cliente.
     *
     * Todo ocurre en una transacción: si el sello no se puede registrar (throttle,
     * tope diario, tarjeta llena…) el token sigue vigente para reintentar.
     *
     * @param  array<string, mixed>  $data
     * @return array{stamp: Stamp, card: CustomerCard, token: SaleToken}
     */
    public function redeem(User $user, ?string $rawCode, array $data = []): array
    {
        $code = $this->normalizeCode($rawCode);

        if ($code === '') {
            throw ValidationException::withMessages([
                'code' => 'Escanea el QR del negocio o escribe el código del token.',
            ]);
        }

        // El token caducado se borra fuera de la transacción: si se lanzara el 422
        // dentro, el rollback desharía el borrado.
        $expired = $this->purgeIfExpired($code);
        $ttlMinutes = $this->ttlMinutes();

        return DB::transaction(function () use ($user, $code, $data, $expired, $ttlMinutes): array {
            /** @var SaleToken|null $token */
            $token = SaleToken::query()->where('code', $code)->lockForUpdate()->first();

            if ($token === null) {
                throw ValidationException::withMessages([
                    'code' => $expired
                        ? $this->expiredMessage($ttlMinutes)
                        : 'El código no es válido o ya se utilizó. Pide uno nuevo al negocio.',
                ]);
            }

            if ($token->isExpired()) {
                throw ValidationException::withMessages([
                    'code' => $this->expiredMessage($token->ttlMinutes()),
                ]);
            }

            $business = $token->business;
            $loyaltyCard = $token->loyaltyCard;

            if ($business === null || $loyaltyCard === null) {
                throw ValidationException::withMessages([
                    'code' => 'El programa de fidelidad de este código ya no está disponible.',
                ]);
            }

            $this->assertCustomerCanRedeem($user, $business, $loyaltyCard);

            $card = $this->customerCardFor($user, $loyaltyCard);

            $stamp = $this->stamps->register(
                business: $business,
                card: $card,
                staff: $token->createdBy,
                data: [
                    'source' => StampSource::Token,
                    'purchase_amount' => $data['purchase_amount'] ?? $token->purchase_amount,
                    'notes' => $token->notes,
                    'meta' => ['sale_token_code' => $token->code],
                ],
            );

            $token->delete();

            return [
                'stamp' => $stamp,
                'card' => $card->refresh(),
                'token' => $token,
            ];
        });
    }

    /**
     * Tarjeta sobre la que aplica el token.
     *
     * @param  mixed  $loyaltyCardId
     */
    private function resolveLoyaltyCard(Business $business, mixed $loyaltyCardId): LoyaltyCard
    {
        if (filled($loyaltyCardId)) {
            /** @var LoyaltyCard|null $card */
            $card = $business->loyaltyCards()->find($loyaltyCardId);

            if ($card === null) {
                throw ValidationException::withMessages([
                    'loyalty_card_id' => 'Esa tarjeta no pertenece a tu negocio.',
                ]);
            }

            return $card;
        }

        /** @var Collection<int, LoyaltyCard> $cards */
        $cards = $business->loyaltyCards()->active()->get();

        if ($cards->isEmpty()) {
            throw ValidationException::withMessages([
                'loyalty_card_id' => 'Crea una tarjeta de fidelidad activa antes de generar tokens de venta.',
            ]);
        }

        if ($cards->count() > 1) {
            throw ValidationException::withMessages([
                'loyalty_card_id' => 'Tienes varias tarjetas activas: indica a cuál aplica el token (`loyalty_card_id`).',
            ]);
        }

        return $cards->first();
    }

    /**
     * El negocio y la tarjeta deben poder seguir vendiendo.
     */
    private function assertBusinessCanSell(Business $business, LoyaltyCard $loyaltyCard): void
    {
        if (! $business->is_active) {
            throw ValidationException::withMessages([
                'business' => 'Tu negocio está desactivado: no puede generar tokens de venta.',
            ]);
        }

        if (! $loyaltyCard->is_active) {
            throw ValidationException::withMessages([
                'loyalty_card_id' => 'Esa tarjeta está inactiva.',
            ]);
        }

        if ($loyaltyCard->expires_at !== null && $loyaltyCard->expires_at->isPast()) {
            throw ValidationException::withMessages([
                'loyalty_card_id' => 'Esa tarjeta ya caducó.',
            ]);
        }
    }

    /**
     * El cliente no puede auto-validarse tokens de su propio negocio.
     */
    private function assertCustomerCanRedeem(User $user, Business $business, LoyaltyCard $loyaltyCard): void
    {
        if ($user->ownsBusiness($business) && ! config('punto_plus.sale_tokens.allow_self_redemption', false)) {
            throw ValidationException::withMessages([
                'code' => 'No puedes validar un token de tu propio negocio.',
            ]);
        }

        if (! $business->is_active) {
            throw ValidationException::withMessages([
                'code' => 'Este negocio no está disponible en este momento.',
            ]);
        }

        if (! $loyaltyCard->is_active || ($loyaltyCard->expires_at !== null && $loyaltyCard->expires_at->isPast())) {
            throw ValidationException::withMessages([
                'code' => 'Este programa de fidelidad ya no está disponible.',
            ]);
        }
    }

    /**
     * Tarjeta del cliente para ese programa: la existente o una nueva (si es público).
     */
    private function customerCardFor(User $user, LoyaltyCard $loyaltyCard): CustomerCard
    {
        /** @var CustomerCard|null $existing */
        $existing = CustomerCard::withTrashed()
            ->where('user_id', $user->getKey())
            ->where('loyalty_card_id', $loyaltyCard->getKey())
            ->first();

        // Si ya tiene tarjeta vigente se usa tal cual; StampService valida si puede sellarse.
        if ($existing !== null && ! $existing->trashed()) {
            return $existing;
        }

        // Sin tarjeta: el negocio acaba de invitarle con el token (sólo programas públicos).
        return $this->cards->join($user, null, $loyaltyCard->getKey());
    }

    /**
     * Borra el token si ya caducó y dice si era el caso.
     */
    private function purgeIfExpired(string $code): bool
    {
        return SaleToken::query()
            ->where('code', $code)
            ->where('expires_at', '<=', now())
            ->delete() > 0;
    }

    private function expiredMessage(int $ttlMinutes): string
    {
        return sprintf(
            'El código caducó: los tokens de venta duran %d minutos. Pide uno nuevo al negocio.',
            max(1, $ttlMinutes),
        );
    }

    private function ttlMinutes(): int
    {
        return max(1, (int) config('punto_plus.sale_tokens.ttl_minutes', 5));
    }

    /**
     * Código normalizado a partir del QR (JSON, deep link o texto) o del campo de texto.
     */
    private function normalizeCode(?string $rawCode): string
    {
        return SaleToken::normalizeCode($this->qrCodes->extractCode($rawCode));
    }

    /**
     * Barre los tokens ya caducados del negocio.
     */
    private function removeExpired(Business $business): void
    {
        SaleToken::query()
            ->forBusiness($business)
            ->where('expires_at', '<=', now())
            ->delete();
    }

    /**
     * Recorta los tokens vivos más antiguos si se supera `max_active`.
     */
    private function enforceActiveLimit(Business $business): void
    {
        $max = (int) config('punto_plus.sale_tokens.max_active', 10);

        if ($max <= 0) {
            return;
        }

        $active = SaleToken::query()->forBusiness($business)->where('expires_at', '>', now())->count();
        $overflow = $active - $max + 1;

        if ($overflow <= 0) {
            return;
        }

        $ids = SaleToken::query()
            ->forBusiness($business)
            ->where('expires_at', '>', now())
            ->orderBy('created_at')
            ->limit($overflow)
            ->pluck('id');

        SaleToken::query()->whereIn('id', $ids->all())->delete();
    }
}
