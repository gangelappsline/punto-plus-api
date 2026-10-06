<?php

namespace App\Policies;

use App\Models\CustomerCard;
use App\Models\User;

class CustomerCardPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, CustomerCard $card): bool
    {
        return $this->isHolder($user, $card)
            || $this->isBusinessOf($user, $card)
            || $user->isAdmin();
    }

    public function viewStamps(User $user, CustomerCard $card): bool
    {
        return $this->view($user, $card);
    }

    /**
     * Solicitar un canje.
     */
    public function redeem(User $user, CustomerCard $card): bool
    {
        return ($this->isHolder($user, $card) || $user->isAdmin()) && $card->isUsable();
    }

    /**
     * Abandonar el programa.
     */
    public function delete(User $user, CustomerCard $card): bool
    {
        return $this->isHolder($user, $card) || $user->isAdmin();
    }

    /**
     * Consultar la ficha del cliente desde el panel del negocio.
     */
    public function viewAsBusiness(User $user, CustomerCard $card): bool
    {
        return $this->isBusinessOf($user, $card) || $user->isAdmin();
    }

    private function isHolder(User $user, CustomerCard $card): bool
    {
        return $card->user_id === $user->getKey();
    }

    private function isBusinessOf(User $user, CustomerCard $card): bool
    {
        return ! $user->isCliente() && $card->business_id === $user->business?->getKey();
    }
}
