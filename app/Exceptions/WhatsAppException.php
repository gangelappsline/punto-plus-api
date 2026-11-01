<?php

namespace App\Exceptions;

use Illuminate\Http\Client\Response;
use RuntimeException;
use Throwable;

/**
 * Error al hablar con WhatsApp Cloud API (Meta).
 *
 * Conserva el `code` y el `error_subcode` que devuelve Meta para poder
 * distinguir un token caducado (190) de un destinatario fuera de la lista de
 * pruebas o de una plantilla sin aprobar.
 */
class WhatsAppException extends RuntimeException
{
    /**
     * @param  array<string, mixed>  $details
     */
    public function __construct(
        string $message,
        public readonly ?int $metaCode = null,
        public readonly ?int $metaSubcode = null,
        public readonly ?string $metaType = null,
        public readonly array $details = [],
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, $metaCode ?? 0, $previous);
    }

    /**
     * @param  array<int, string>  $missing
     */
    public static function notConfigured(array $missing = []): self
    {
        $message = 'WhatsApp Cloud API no está configurado.';

        if ($missing !== []) {
            $message .= ' Faltan: '.implode(', ', $missing).'.';
        }

        return new self($message, details: ['missing' => $missing]);
    }

    public static function disabled(): self
    {
        return new self('El envío de WhatsApp está desactivado (WHATSAPP_ENABLED=false).');
    }

    public static function invalidRecipient(?string $raw): self
    {
        return new self(
            'El número «'.$raw.'» no es válido. Envíalo en formato internacional E.164, por ejemplo +5215512345678.',
            details: ['to' => $raw],
        );
    }

    public static function fromResponse(Response $response): self
    {
        $body = $response->json();
        $error = is_array($body) && is_array($body['error'] ?? null) ? $body['error'] : [];

        $message = trim((string) ($error['message'] ?? '')) ?: 'WhatsApp Cloud API respondió con un error.';

        return new self(
            $message,
            isset($error['code']) ? (int) $error['code'] : null,
            isset($error['error_subcode']) ? (int) $error['error_subcode'] : null,
            $error['type'] ?? null,
            [
                'status' => $response->status(),
                'error' => $error !== [] ? $error : $response->body(),
            ],
        );
    }
}
