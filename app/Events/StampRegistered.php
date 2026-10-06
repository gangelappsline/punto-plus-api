<?php

namespace App\Events;

use App\Models\Stamp;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Se dispara después de registrar un sello (escaneo o registro manual).
 */
class StampRegistered
{
    use Dispatchable, SerializesModels;

    public function __construct(public readonly Stamp $stamp)
    {
    }
}
