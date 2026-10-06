<?php

namespace App\Services;

use App\Enums\CardStatus;
use App\Enums\RedemptionStatus;
use App\Events\RedemptionProcessed;
use App\Events\RedemptionRequested;
use App\Models\Business;
use App\Models\CustomerCard;
use App\Models\Redemption;
use App\Models\Reward;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Ciclo de vida del canje de recompensas.
 *
 * Flujo:
 *   1. El cliente solicita el canje -> `pending` (se descuentan sus sellos según
 *      `punto_plus.cards.reset_progress_on_redeem` y se reserva una unidad de stock).
 *   2. El negocio valida el código -> `completed`, o lo rechaza -> `rejected`
 *      (se devuelven los sellos y el stock).
 *   3. El cliente puede cancelar mientras siga `pending`.
 */
final class RedemptionService
{
    /**
     * El cliente solicita canjear una recompensa de una de sus tarjetas.
     */
    public function request(CustomerCard $card, Reward $reward, User $customer, ?string $notes = null): Redemption
    {
        $this->assertCardOwnership($card, $customer);
        $this->assertRewardBelongsToCard($card, $reward);
        $this->assertRewardIsRedeemable($reward);
        $this->assertCardHasEnoughStamps($card, $reward);
        $this->assertNoPendingRedemption($card);

        $redemption = DB::transaction(function () use ($card, $reward, $customer, $notes): Redemption {
            /** @var CustomerCard $lockedCard */
            $lockedCard = CustomerCard::whereKey($card->getKey())->lockForUpdate()->firstOrFail();
            /** @var Reward $lockedReward */
            $lockedReward = Reward::whereKey($reward->getKey())->lockForUpdate()->firstOrFail();

            if ($lockedReward->stock !== null) {
                $lockedReward->stock = max(0, $lockedReward->stock - 1);
            }

            $lockedReward->redemptions_count = $lockedReward->redemptions_count + 1;
            $lockedReward->save();

            $stampsUsed = $this->stampsToUse($lockedCard, $lockedReward);

            $lockedCard->stamps_count = max(0, $lockedCard->stamps_count - $stampsUsed);
            $lockedCard->status = $lockedCard->stamps_count >= $lockedCard->required_stamps
                ? CardStatus::Completed
                : CardStatus::Active;
            $lockedCard->completed_at = $lockedCard->status === CardStatus::Completed
                ? ($lockedCard->completed_at ?? now())
                : null;
            $lockedCard->save();

            return Redemption::create([
                'customer_card_id' => $lockedCard->getKey(),
                'reward_id' => $lockedReward->getKey(),
                'business_id' => $lockedCard->business_id,
                'user_id' => $customer->getKey(),
                'status' => RedemptionStatus::Pending,
                'stamps_used' => $stampsUsed,
                'redeemed_at' => now(),
                'notes' => $notes,
            ]);
        }, 3);

        RedemptionRequested::dispatch($redemption);

        return $redemption->load(['reward', 'customerCard.loyaltyCard', 'business']);
    }

    /**
     * El negocio valida el canje (por ID o por código).
     */
    public function approve(Redemption $redemption, User $staff): Redemption
    {
        $this->assertBusinessOwnership($redemption, $staff);
        $this->assertPending($redemption);

        $redemption = DB::transaction(function () use ($redemption, $staff): Redemption {
            $redemption->status = RedemptionStatus::Completed;
            $redemption->approved_by = $staff->getKey();
            $redemption->approved_at = now();
            $redemption->save();

            $card = $redemption->customerCard()->lockForUpdate()->firstOrFail();
            $card->rewards_redeemed = $card->rewards_redeemed + 1;
            $card->save();

            return $redemption;
        }, 3);

        RedemptionProcessed::dispatch($redemption->load('reward'));

        return $redemption->load(['reward', 'customerCard.loyaltyCard', 'user', 'business', 'approvedBy']);
    }

    /**
     * El negocio rechaza el canje: se devuelven sellos y stock.
     */
    public function reject(Redemption $redemption, User $staff, ?string $reason = null): Redemption
    {
        $this->assertBusinessOwnership($redemption, $staff);

        return $this->close($redemption, RedemptionStatus::Rejected, $staff, $reason);
    }

    /**
     * El cliente cancela su propia solicitud mientras sigue pendiente.
     */
    public function cancel(Redemption $redemption, User $customer): Redemption
    {
        if ($redemption->user_id !== $customer->getKey() && ! $customer->isAdmin()) {
            throw new AuthorizationException('Ese canje no te pertenece.');
        }

        return $this->close($redemption, RedemptionStatus::Cancelled, null, null);
    }

    /**
     * El negocio teclea/escanea el código que muestra la app del cliente.
     */
    public function completeByCode(Business $business, string $rawCode, User $staff): Redemption
    {
        $code = mb_strtoupper(trim($rawCode));

        $redemption = Redemption::where('business_id', $business->getKey())
            ->where('code', $code)
            ->first();

        if ($redemption === null) {
            throw ValidationException::withMessages([
                'code' => 'No encontramos ningún canje pendiente con ese código.',
            ]);
        }

        return $this->approve($redemption, $staff);
    }

    // -----------------------------------------------------------------
    // Internals
    // -----------------------------------------------------------------

    private function close(Redemption $redemption, RedemptionStatus $status, ?User $staff, ?string $reason): Redemption
    {
        $this->assertPending($redemption);

        $redemption = DB::transaction(function () use ($redemption, $status, $staff, $reason): Redemption {
            $redemption->status = $status;
            $redemption->approved_by = $staff?->getKey();
            $redemption->approved_at = now();

            if (filled($reason)) {
                $redemption->notes = trim(($redemption->notes ? $redemption->notes.' | ' : '').$reason);
            }

            $redemption->save();

            // Devolvemos los sellos consumidos y el stock reservado.
            $card = $redemption->customerCard()->lockForUpdate()->firstOrFail();

            $required = $card->required_stamps;

            $card->stamps_count = min($required, $card->stamps_count + (int) $redemption->stamps_used);

            if ($card->stamps_count >= $required) {
                $card->status = CardStatus::Completed;
                $card->completed_at ??= now();
            }

            $card->save();

            $reward = $redemption->reward()->lockForUpdate()->first();

            if ($reward !== null) {
                if ($reward->stock !== null) {
                    $reward->stock = $reward->stock + 1;
                }

                $reward->redemptions_count = max(0, $reward->redemptions_count - 1);
                $reward->save();
            }

            return $redemption;
        }, 3);

        RedemptionProcessed::dispatch($redemption->load('reward'));

        return $redemption->load(['reward', 'customerCard.loyaltyCard', 'business', 'user', 'approvedBy']);
    }

    /**
     * Sellos que consume el canje.
     *
     * - `reset_progress_on_redeem` = true (por defecto): la tarjeta vuelve a cero al
     *   canjear (empieza un ciclo nuevo).
     * - false: sólo se descuentan los sellos que cuesta la recompensa, de modo que el
     *   cliente conserva el excedente.
     */
    private function stampsToUse(CustomerCard $card, Reward $reward): int
    {
        $count = max(0, (int) $card->stamps_count);

        if ((bool) config('punto_plus.cards.reset_progress_on_redeem', true)) {
            return $count;
        }

        return min(max(0, (int) $reward->required_stamps), $count);
    }

    private function assertCardOwnership(CustomerCard $card, User $user): void
    {
        if ($card->user_id !== $user->getKey() && ! $user->isAdmin()) {
            throw new AuthorizationException('Esa tarjeta no te pertenece.');
        }
    }

    private function assertRewardBelongsToCard(CustomerCard $card, Reward $reward): void
    {
        if ($reward->loyalty_card_id !== $card->loyalty_card_id || $reward->business_id !== $card->business_id) {
            throw ValidationException::withMessages([
                'reward_id' => 'Esa recompensa no pertenece al programa de esta tarjeta.',
            ]);
        }
    }

    private function assertRewardIsRedeemable(Reward $reward): void
    {
        if (! $reward->is_active) {
            throw ValidationException::withMessages([
                'reward_id' => 'La recompensa no está disponible.',
            ]);
        }

        if (! $reward->hasStock()) {
            throw ValidationException::withMessages([
                'reward_id' => 'La recompensa está agotada.',
            ]);
        }
    }

    private function assertCardHasEnoughStamps(CustomerCard $card, Reward $reward): void
    {
        if (! $card->meetsRequirementFor($reward)) {
            throw ValidationException::withMessages([
                'reward_id' => sprintf(
                    'Necesitas %d sellos y tienes %d.',
                    (int) $reward->required_stamps,
                    (int) $card->stamps_count,
                ),
            ]);
        }
    }

    private function assertNoPendingRedemption(CustomerCard $card): void
    {
        $pending = Redemption::where('customer_card_id', $card->getKey())
            ->where('status', RedemptionStatus::Pending->value)
            ->exists();

        if ($pending) {
            throw ValidationException::withMessages([
                'reward_id' => 'Ya tienes un canje pendiente en esta tarjeta. Muéstrale el código al negocio.',
            ]);
        }
    }

    private function assertBusinessOwnership(Redemption $redemption, User $staff): void
    {
        if (! $staff->isAdmin() && $redemption->business_id !== $staff->business?->getKey()) {
            throw new AuthorizationException('Ese canje pertenece a otro negocio.');
        }
    }

    private function assertPending(Redemption $redemption): void
    {
        if (! $redemption->isPending()) {
            throw ValidationException::withMessages([
                'code' => 'Este canje ya fue procesado ('.$redemption->status->value.').',
            ]);
        }
    }
}
