<?php

namespace App\Events;

use App\Models\CustomerCard;
use App\Models\Reward;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * La tarjeta del cliente alcanzó los sellos necesarios: tiene recompensa disponible.
 */
class CardCompleted
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly CustomerCard $card,
        public readonly ?Reward $reward = null,
    ) {
    }
}
