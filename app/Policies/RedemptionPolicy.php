<?php

namespace App\Policies;

use App\Models\Redemption;
use App\Models\User;

class RedemptionPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Redemption $redemption): bool
    {
        return $user->isAdmin() || $this->isCustomer($user, $redemption) || $this->isBusiness($user, $redemption);
    }

    /**
     * Validar y entregar el premio.
     */
    public function approve(User $user, Redemption $redemption): bool
    {
        return $this->isBusiness($user, $redemption);
    }

    public function reject(User $user, Redemption $redemption): bool
    {
        return $this->isBusiness($user, $redemption);
    }

    public function cancel(User $user, Redemption $redemption): bool
    {
        return $this->isCustomer($user, $redemption) && $redemption->isPending();
    }

    private function isCustomer(User $user, Redemption $redemption): bool
    {
        return $redemption->user_id === $user->getKey();
    }

    private function isBusiness(User $user, Redemption $redemption): bool
    {
        return $user->isAdmin() || $redemption->business_id === $user->business?->getKey();
    }
}
