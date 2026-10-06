<?php

namespace App\Events;

use App\Models\Redemption;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * El cliente solicitó un canje (queda pendiente de validación en el negocio).
 */
class RedemptionRequested
{
    use Dispatchable, SerializesModels;

    public function __construct(public readonly Redemption $redemption)
    {
    }
}
