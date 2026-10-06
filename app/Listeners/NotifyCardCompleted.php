<?php

namespace App\Listeners;

use App\Events\CardCompleted;
use Illuminate\Support\Facades\Log;

/**
 * Punto de extensión para avisar al cliente de que ya tiene una recompensa.
 *
 * Aquí es donde se conectaría el envío de push (FCM/APNs), email o un
 * webhook al negocio; el evento ya lleva la tarjeta y la recompensa alcanzada.
 */
class NotifyCardCompleted
{
    public function handle(CardCompleted $event): void
    {
        Log::info('Tarjeta completada: recompensa disponible', [
            'customer_card_id' => event->card->getKey(),
            'user_id' => event->card->user_id,
            'business_id' => event->card->business_id,
            'loyalty_card_id' => event->card->loyalty_card_id,
            'reward_id' => event->reward?->getKey(),
            'stamps' => event->card->stamps_count,
        ]);
    }
}
