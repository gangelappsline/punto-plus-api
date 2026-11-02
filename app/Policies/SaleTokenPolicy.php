<?php

namespace App\Policies;

use App\Models\SaleToken;
use App\Models\User;

/**
 * Los tokens de venta sólo los gestiona el negocio que los genera.
 */
class SaleTokenPolicy
{
    public function viewAny(?User $user): bool
    {
        return $user !== null;
    }

    public function view(?User $user, SaleToken $token): bool
    {
        return $this->owns($user, $token);
    }

    public function create(User $user): bool
    {
        return $user->isNegocio() || $user->isAdmin();
    }

    public function delete(User $user, SaleToken $token): bool
    {
        return $this->owns($user, $token);
    }

    private function owns(?User $user, SaleToken $token): bool
    {
        if ($user === null) {
            return false;
        }

        return $user->isAdmin() || $token->business?->user_id === $user->getKey();
    }
}
