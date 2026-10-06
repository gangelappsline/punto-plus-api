<?php

namespace App\Events;

use App\Models\Stamp;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Se dispara cuando un negocio anula un sello.
 */
class StampVoided
{
    use Dispatchable, SerializesModels;

    public function __construct(public readonly Stamp $stamp)
    {
    }
}
