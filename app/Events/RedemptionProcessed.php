<?php

namespace App\Events;

use App\Models\Redemption;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * El canje pasó a estado final: completado, rechazado o cancelado.
 */
class RedemptionProcessed
{
    use Dispatchable, SerializesModels;

    public function __construct(public readonly Redemption $redemption)
    {
    }
}
