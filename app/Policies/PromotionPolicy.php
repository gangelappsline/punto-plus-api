<?php

namespace App\Policies;

use App\Models\Promotion;
use App\Models\User;

class PromotionPolicy
{
    public function viewAny(?User $user): bool
    {
        return true;
    }

    public function view(?User $user, Promotion $promotion): bool
    {
        return $this->owns($user, $promotion) || $promotion->isActiveNow();
    }

    public function create(User $user): bool
    {
        return $user->isNegocio() || $user->isAdmin();
    }

    public function update(User $user, Promotion $promotion): bool
    {
        return $this->owns($user, $promotion);
    }

    public function delete(User $user, Promotion $promotion): bool
    {
        return $this->owns($user, $promotion);
    }

    private function owns(?User $user, Promotion $promotion): bool
    {
        if ($user === null) {
            return false;
        }

        return $user->isAdmin() || $promotion->business_id === $user->business?->getKey();
    }
}
