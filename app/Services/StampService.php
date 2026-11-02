<?php

namespace App\Services;

use App\Enums\CardStatus;
use App\Enums\StampSource;
use App\Events\CardCompleted;
use App\Events\StampRegistered;
use App\Events\StampVoided;
use App\Models\Business;
use App\Models\CustomerCard;
use App\Models\Stamp;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Registro y anulación de sellos.
 *
 * Reglas de negocio (todas config-origen, ver config/punto_plus.php):
 *  - sólo el negocio dueño de la tarjeta puede sellarla;
 *  - la tarjeta debe estar activa y el programa vigente;
 *  - se aplica un throttle anti-fraude entre sellos y un máximo diario opcional;
 *  - al alcanzar los sellos requeridos la tarjeta pasa a `completed`.
 */
final class StampService
{
    /**
     * Registra un sello y actualiza el progreso de la tarjeta.
     *
     * @param  array<string, mixed>  $data
     */
    public function register(Business $business, CustomerCard $card, User $staff, array $data = []): Stamp
    {
        $this->assertCardBelongsToBusiness($card, $business);
        $this->assertCardIsUsable($card);

        if (isset($data['loyalty_card_id']) && $data['loyalty_card_id'] !== $card->loyalty_card_id) {
            throw ValidationException::withMessages([
                'loyalty_card_id' => 'Ese sello no corresponde al programa de esta tarjeta.',
            ]);
        }

        $stampedAt = isset($data['stamped_at']) ? \Illuminate\Support\Facades\Date::parse($data['stamped_at']) : now();

        $this->assertNotThrottled($card, $stampedAt);
        $this->assertDailyLimitNotReached($card);
        $this->assertPurchaseAmountIsValid($card, $data);

        $outcome = DB::transaction(function () use ($business, $card, $staff, $data, $stampedAt): array {
            /** @var CustomerCard $locked */
            $locked = CustomerCard::whereKey($card->getKey())->lockForUpdate()->firstOrFail();
            $loyaltyCard = $locked->loyaltyCard;

            // Si el ciclo venció (tarjetas "en un lapso de días") arranca uno nuevo
            // antes de sumar esta compra.
            $cycleRestarted = false;

            if ($locked->cycle_expires_at !== null && $locked->cycle_expires_at->isPast()) {
                $locked->resetCycle($stampedAt);
                $cycleRestarted = true;
            } elseif ($locked->cycle_started_at === null && $loyaltyCard?->hasPeriod()) {
                $locked->startCycle($stampedAt);
            }

            // Tarjetas por consumo: los puntos los define la tarjeta del negocio.
            $points = $locked->isSpend()
                ? (int) $loyaltyCard?->pointsForAmount((float) $data['purchase_amount'])
                : 0;

            $stamp = Stamp::create([
                'customer_card_id' => $locked->getKey(),
                'business_id' => $business->getKey(),
                'loyalty_card_id' => $locked->loyalty_card_id,
                'registered_by' => $staff->getKey(),
                'source' => $data['source'] ?? StampSource::Scan,
                'purchase_amount' => $data['purchase_amount'] ?? null,
                'points_awarded' => $points,
                'notes' => $data['notes'] ?? null,
                'stamped_at' => $stampedAt,
                'meta' => isset($data['idempotency_key']) ? ['idempotency_key' => $data['idempotency_key']] : null,
            ]);

            $wasCompleted = $locked->status === CardStatus::Completed;

            $locked->stamps_count = $locked->stamps_count + 1;
            $locked->total_stamps_earned = $locked->total_stamps_earned + 1;
            $locked->points_count = $locked->points_count + $points;
            $locked->total_points_earned = $locked->total_points_earned + $points;
            $locked->last_stamp_at = $stampedAt;

            // Las tarjetas por consumo nunca se "completan": acumulan puntos y el
            // cliente canjea cuando alcanza el umbral de alguna recompensa.
            if (! $locked->isSpend() && $locked->stamps_count >= $locked->required_stamps) {
                $locked->status = CardStatus::Completed;
                $locked->completed_at ??= now();

                // Cada ciclo completado genera exactamente una recompensa.
                if (! $wasCompleted) {
                    $locked->rewards_earned = $locked->rewards_earned + 1;
                }
            }

            $locked->save();

            return [
                'stamp' => $stamp,
                'card' => $locked,
                'newly_completed' => ! $wasCompleted,
                'points_awarded' => $points,
                'cycle_restarted' => $cycleRestarted,
            ];
        }, 3);

        /** @var Stamp $stamp */
        $stamp = $outcome['stamp'];
        /** @var CustomerCard $updated */
        $updated = $outcome['card'];

        StampRegistered::dispatch($stamp);

        if ($outcome['newly_completed'] && ! $updated->isSpend() && $updated->stamps_count >= $updated->required_stamps) {
            CardCompleted::dispatch($updated, $updated->loyaltyCard?->rewardFor($updated->stamps_count));
        }

        return $stamp->fresh(['customerCard.loyaltyCard', 'registeredBy', 'business']);
    }

    /**
     * Anula un sello (corrección o fraude detectado) y revierte el progreso.
     */
    public function void(Stamp $stamp, User $staff): Stamp
    {
        if (! $staff->isAdmin() && ! $staff->ownsBusiness($stamp->business)) {
            throw new AuthorizationException('Sólo el negocio que registró el sello puede anularlo.');
        }

        DB::transaction(function () use ($stamp): void {
            /** @var CustomerCard $card */
            $card = CustomerCard::whereKey($stamp->customer_card_id)->lockForUpdate()->firstOrFail();

            $stamp->delete();

            $card->stamps_count = max(0, $card->stamps_count - 1);
            $card->total_stamps_earned = max(0, $card->total_stamps_earned - 1);

            // En las tarjetas por consumo también se revierten los puntos.
            $points = (int) $stamp->points_awarded;
            $card->points_count = max(0, $card->points_count - $points);
            $card->total_points_earned = max(0, $card->total_points_earned - $points);

            if (! $card->isSpend() && $card->stamps_count < $card->required_stamps) {
                $card->status = CardStatus::Active;
                $card->completed_at = null;
            }

            $card->save();
        }, 3);

        StampVoided::dispatch($stamp);

        return $stamp;
    }

    // -----------------------------------------------------------------
    // Guards
    // -----------------------------------------------------------------

    private function assertCardBelongsToBusiness(CustomerCard $card, Business $business): void
    {
        if ($card->business_id !== $business->getKey()) {
            throw new AuthorizationException('Esa tarjeta pertenece a otro negocio.');
        }
    }

    private function assertCardIsUsable(CustomerCard $card): void
    {
        if (! $card->isUsable()) {
            throw ValidationException::withMessages([
                'code' => 'La tarjeta no está activa (bloqueada, caducada o el programa finalizó).',
            ]);
        }
    }

    private function assertNotThrottled(CustomerCard $card, \Illuminate\Support\Carbon $stampedAt): void
    {
        $seconds = (int) config('punto_plus.cards.stamp_throttle_seconds', 0);

        if ($seconds <= 0) {
            return;
        }

        $lastStamp = Stamp::where('customer_card_id', $card->getKey())
            ->latest('stamped_at')
            ->first();

        if ($lastStamp === null) {
            return;
        }

        $elapsed = $stampedAt->getTimestamp() - $lastStamp->stamped_at->getTimestamp();

        if ($elapsed < $seconds) {
            throw ValidationException::withMessages([
                'code' => sprintf(
                    'Espera %d segundos antes de registrar otro sello en esta tarjeta.',
                    $seconds - max(0, $elapsed),
                ),
            ]);
        }
    }

    /**
     * En las tarjetas por consumo el monto de la compra es obligatorio y debe
     * alcanzar al menos un bloque de gasto.
     *
     * @param  array<string, mixed>  $data
     */
    private function assertPurchaseAmountIsValid(CustomerCard $card, array $data): void
    {
        if (! $card->isSpend()) {
            return;
        }

        $amount = $data['purchase_amount'] ?? null;

        if ($amount === null || $amount === '') {
            throw ValidationException::withMessages([
                'purchase_amount' => 'Esta tarjeta acumula puntos por consumo: indica el monto de la compra.',
            ]);
        }

        $loyaltyCard = $card->loyaltyCard;

        if ($loyaltyCard !== null && $loyaltyCard->pointsForAmount((float) $amount) <= 0) {
            throw ValidationException::withMessages([
                'purchase_amount' => sprintf(
                    'El monto no genera puntos: cada $%s de consumo suma %d puntos.',
                    number_format((float) $loyaltyCard->spend_amount_per_block, 2, '.', ','),
                    (int) $loyaltyCard->points_per_block,
                ),
            ]);
        }
    }

    private function assertDailyLimitNotReached(CustomerCard $card): void
    {
        $limit = (int) config('punto_plus.cards.max_stamps_per_day', 0);

        if ($limit <= 0) {
            return;
        }

        $today = Stamp::where('customer_card_id', $card->getKey())
            ->whereDate('stamped_at', now()->toDateString())
            ->count();

        if ($today >= $limit) {
            throw ValidationException::withMessages([
                'code' => 'Esta tarjeta alcanzó el máximo de sellos permitidos por día.',
            ]);
        }
    }
}
