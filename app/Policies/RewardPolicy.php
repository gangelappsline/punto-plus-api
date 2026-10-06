<?php

namespace App\Policies;

use App\Models\Reward;
use App\Models\User;

class RewardPolicy
{
    public function viewAny(?User $user): bool
    {
        return true;
    }

    public function view(?User $user, Reward $reward): bool
    {
        return $this->owns($user, $reward) || $reward->is_active;
    }

    public function create(User $user): bool
    {
        return $user->isNegocio() || $user->isAdmin();
    }

    public function update(User $user, Reward $reward): bool
    {
        return $this->owns($user, $reward);
    }

    public function delete(User $user, Reward $reward): bool
    {
        return $this->owns($user, $reward);
    }

    private function owns(?User $user, Reward $reward): bool
    {
        if ($user === null) {
            return false;
        }

        return $user->isAdmin() || $reward->business_id === $user->business?->getKey();
    }
}
