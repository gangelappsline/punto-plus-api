<?php

namespace App\Services;

use App\Enums\CardStatus;
use App\Models\Business;
use App\Models\CustomerCard;
use App\Models\LoyaltyCard;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Alta de clientes en programas de fidelidad y utilidades de progreso.
 */
final class CustomerCardService
{
    public function __construct(private readonly QrCodeService $qrCodes)
    {
    }

    /**
     * Une al cliente al programa identificado por un QR (join_code) y devuelve
     * su tarjeta. Si ya estaba unido, devuelve la tarjeta existente.
     */
    public function join(User $user, ?string $rawCode = null, ?string $loyaltyCardId = null): CustomerCard
    {
        $card = $loyaltyCardId !== null
            ? LoyaltyCard::with('business')->findOrFail($loyaltyCardId)
            : $this->findByJoinCode($rawCode);

        if (! $card->is_active || ! $card->business?->is_active) {
            throw ValidationException::withMessages([
                'code' => 'Este programa de fidelidad no está disponible.',
            ]);
        }

        if ($card->expires_at !== null && $card->expires_at->isPast()) {
            throw ValidationException::withMessages([
                'code' => 'El programa de fidelidad ha caducado.',
            ]);
        }

        if (! $card->is_public) {
            throw ValidationException::withMessages([
                'code' => 'Este programa requiere una invitación del negocio.',
            ]);
        }

        return DB::transaction(function () use ($user, $card): CustomerCard {
            /** @var CustomerCard|null $existing */
            $existing = CustomerCard::withTrashed()
                ->where('user_id', $user->getKey())
                ->where('loyalty_card_id', $card->getKey())
                ->lockForUpdate()
                ->first();

            if ($existing !== null) {
                $wasTrashed = $existing->trashed();

                // Si el cliente había abandonado el programa o su tarjeta caducó, se
                // recupera (con su progreso) en lugar de crear una duplicada.
                if ($wasTrashed) {
                    $existing->restore();
                }

                if ($wasTrashed || $existing->status === CardStatus::Expired) {
                    // El negocio pudo ampliar la vigencia del programa: se sincroniza.
                    $existing->expires_at = $card->expires_at;

                    $this->refreshStatus($existing);
                }

                return $existing;
            }

            return CustomerCard::create([
                'user_id' => $user->getKey(),
                'loyalty_card_id' => $card->getKey(),
                'business_id' => $card->business_id,
                'stamps_count' => 0,
                'total_stamps_earned' => 0,
                'status' => CardStatus::Active,
                'expires_at' => $card->expires_at,
            ])->load(['loyaltyCard.business', 'business']);
        }, 3);
    }

    /**
     * Localiza el programa a partir del payload del QR del negocio.
     */
    public function findByJoinCode(?string $rawCode): LoyaltyCard
    {
        $code = $this->qrCodes->extractCode($rawCode);

        if (blank($code)) {
            throw ValidationException::withMessages([
                'code' => 'El código del QR no es válido.',
            ]);
        }

        $card = LoyaltyCard::with('business')->where('join_code', $code)->first();

        if ($card === null) {
            throw ValidationException::withMessages([
                'code' => 'No encontramos ningún programa con ese código.',
            ]);
        }

        return $card;
    }

    /**
     * Localiza la tarjeta del cliente que el negocio acaba de escanear.
     */
    public function findByCardCode(Business $business, string $rawCode): CustomerCard
    {
        $code = $this->qrCodes->extractCode($rawCode);

        if (blank($code)) {
            throw ValidationException::withMessages([
                'code' => 'El código de la tarjeta no es válido.',
            ]);
        }

        $card = CustomerCard::with(['loyaltyCard', 'user'])
            ->where('business_id', $business->getKey())
            ->where('code', $code)
            ->first();

        if ($card === null) {
            // Distinguimos "código inexistente" de "tarjeta de otro negocio" para
            // que el mensaje sea útil en el mostrador.
            $existsElsewhere = CustomerCard::withTrashed()->where('code', $code)->exists();

            throw ValidationException::withMessages([
                'code' => $existsElsewhere
                    ? 'Esa tarjeta pertenece a otro negocio.'
                    : 'No encontramos ninguna tarjeta con ese código.',
            ]);
        }

        return $card;
    }

    /**
     * Comprueba que la tarjeta pertenece al usuario autenticado.
     */
    public function assertOwnership(CustomerCard $card, User $user): void
    {
        if ($card->user_id !== $user->getKey() && ! $user->isAdmin()) {
            throw new AuthorizationException('Esa tarjeta no te pertenece.');
        }
    }

    /**
     * Recalcula el estado de la tarjeta a partir de su contador de sellos.
     */
    public function refreshStatus(CustomerCard $card): CustomerCard
    {
        $required = $card->required_stamps;

        $card->status = $card->stamps_count >= $required
            ? CardStatus::Completed
            : CardStatus::Active;

        $card->completed_at = $card->status === CardStatus::Completed
            ? ($card->completed_at ?? now())
            : null;

        $card->save();

        return $card;
    }
}
