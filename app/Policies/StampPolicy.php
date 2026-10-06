<?php

namespace App\Policies;

use App\Models\Stamp;
use App\Models\User;

class StampPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Stamp $stamp): bool
    {
        return $user->isAdmin() || $stamp->business_id === $user->business?->getKey();
    }

    /**
     * Anular un sello (corrección de errores o fraude).
     */
    public function delete(User $user, Stamp $stamp): bool
    {
        return $user->isAdmin() || $stamp->business_id === $user->business?->getKey();
    }
}
