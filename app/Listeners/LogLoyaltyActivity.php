<?php

namespace App\Listeners;

use App\Events\RedemptionProcessed;
use App\Events\RedemptionRequested;
use App\Events\StampRegistered;
use App\Events\StampVoided;
use Illuminate\Support\Facades\Log;

/**
 * Traza de auditoría de la actividad de fidelidad (útil para depurar disputas
 * entre cliente y negocio sobre sellos o canjes).
 */
class LogLoyaltyActivity
{
    public function handle(StampRegistered|StampVoided|RedemptionRequested|RedemptionProcessed $event): void
    {
        $payload = [
            'event' => class_basename($event),
        ];

        if (property_exists($event, 'stamp')) {
            $payload += [
                'stamp_id' => $event->stamp->getKey(),
                'customer_card_id' => $event->stamp->customer_card_id,
                'business_id' => $event->stamp->business_id,
                'source' => $event->stamp->source->value,
            ];
        }

        if (property_exists($event, 'redemption')) {
            $payload += [
                'redemption_id' => $event->redemption->getKey(),
                'code' => $event->redemption->code,
                'status' => $event->redemption->status->value,
                'customer_card_id' => $event->redemption->customer_card_id,
                'business_id' => $event->redemption->business_id,
            ];
        }

        Log::channel(config('logging.default'))->info('punto_plus.activity', $payload);
    }
}
