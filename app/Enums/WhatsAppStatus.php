<?php

namespace App\Enums;

/**
 * Estado de un mensaje de WhatsApp.
 *
 * Los estados de salida siguen los acuses de Meta (`sent` → `delivered` →
 * `read`); `received` es el de los mensajes que llegan del cliente y `failed`
 * el de los envíos rechazados por la Cloud API.
 */
enum WhatsAppStatus: string
{
    case Queued = 'queued';
    case Sent = 'sent';
    case Delivered = 'delivered';
    case Read = 'read';
    case Received = 'received';
    case Failed = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::Queued => 'En cola',
            self::Sent => 'Enviado',
            self::Delivered => 'Entregado',
            self::Read => 'Leído',
            self::Received => 'Recibido',
            self::Failed => 'Fallido',
        };
    }

    /**
     * Estado equivalente a partir del valor que envía Meta en el webhook.
     */
    public static function fromMeta(?string $status): ?self
    {
        return match ($status) {
            'sent' => self::Sent,
            'delivered' => self::Delivered,
            'read' => self::Read,
            'failed' => self::Failed,
            default => null,
        };
    }

    /**
     * Los acuses no llegan siempre en orden; el estado sólo avanza.
     */
    public function rank(): int
    {
        return match ($this) {
            self::Queued => 0,
            self::Received => 1,
            self::Sent => 2,
            self::Delivered => 3,
            self::Read => 4,
            self::Failed => 5,
        };
    }

    public function isForwardFrom(self $current): bool
    {
        return $this->rank() >= $current->rank();
    }
}
