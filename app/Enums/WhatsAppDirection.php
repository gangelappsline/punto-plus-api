<?php

namespace App\Enums;

/**
 * Sentido de un mensaje de WhatsApp registrado en `whatsapp_messages`.
 */
enum WhatsAppDirection: string
{
    case Outgoing = 'outgoing';
    case Incoming = 'incoming';

    public function label(): string
    {
        return match ($this) {
            self::Outgoing => 'Enviado por el negocio',
            self::Incoming => 'Recibido del cliente',
        };
    }
}
