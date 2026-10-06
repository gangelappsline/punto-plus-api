<?php

namespace App\Policies;

use App\Models\LoyaltyCard;
use App\Models\User;

class LoyaltyCardPolicy
{
    public function viewAny(?User $user): bool
    {
        return true;
    }

    public function view(?User $user, LoyaltyCard $card): bool
    {
        if ($this->manages($user, $card)) {
            return true;
        }

        return $card->is_public && $card->isActiveNow();
    }

    public function create(User $user): bool
    {
        return $user->isNegocio() || $user->isAdmin();
    }

    public function update(User $user, LoyaltyCard $card): bool
    {
        return $this->manages($user, $card);
    }

    public function delete(User $user, LoyaltyCard $card): bool
    {
        return $this->manages($user, $card);
    }

    public function manageAssets(User $user, LoyaltyCard $card): bool
    {
        return $this->manages($user, $card);
    }

    public function manageRewards(User $user, LoyaltyCard $card): bool
    {
        return $this->manages($user, $card);
    }

    public function viewStats(User $user, LoyaltyCard $card): bool
    {
        return $this->manages($user, $card);
    }

    private function manages(?User $user, LoyaltyCard $card): bool
    {
        if ($user === null) {
            return false;
        }

        return $user->isAdmin() || $card->business_id === $user->business?->getKey();
    }
}
