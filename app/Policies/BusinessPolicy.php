<?php

namespace App\Policies;

use App\Models\Business;
use App\Models\User;

class BusinessPolicy
{
    public function viewAny(?User $user): bool
    {
        return true;
    }

    public function view(?User $user, Business $business): bool
    {
        return $business->is_active || $this->owns($user, $business);
    }

    public function update(User $user, Business $business): bool
    {
        return $this->owns($user, $business) || $user->isAdmin();
    }

    public function delete(User $user, Business $business): bool
    {
        return $this->owns($user, $business) || $user->isAdmin();
    }

    /**
     * Subir logo/fondo/sello y cambiar la configuración de tarjeta.
     */
    public function manageBranding(User $user, Business $business): bool
    {
        return $this->owns($user, $business) || $user->isAdmin();
    }

    private function owns(?User $user, Business $business): bool
    {
        return $user !== null && ($user->ownsBusiness($business) || $user->isAdmin());
    }
}
