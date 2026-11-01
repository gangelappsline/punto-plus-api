<?php

namespace App\Console\Commands;

use App\Exceptions\WhatsAppException;
use App\Models\Business;
use App\Services\WhatsApp\WhatsAppClient;
use App\Services\WhatsApp\WhatsAppMessenger;
use Illuminate\Console\Command;

/**
 * Prueba de humo de la integración: envía un mensaje real con la configuración
 * del `.env` y muestra el id que devuelve Meta (o el error con su código).
 *
 *   php artisan punto-plus:whatsapp-test +5215512345678
 *   php artisan punto-plus:whatsapp-test +5215512345678 --template=hello_world --language=en_US
 */
class TestWhatsApp extends Command
{
    protected $signature = 'punto-plus:whatsapp-test
        {to : Número destino en formato internacional E.164 (p. ej. +5215512345678)}
        {--template= : Nombre de una plantilla aprobada (si se omite, se envía texto libre)}
        {--language= : Código de idioma de la plantilla (por defecto WHATSAPP_TEMPLATE_LANGUAGE)}
        {--text= : Texto a enviar cuando no se usa plantilla}
        {--business= : UUID del negocio al que atribuir el envío}';

    protected $description = 'Envía un mensaje de WhatsApp de prueba con la configuración actual';

    public function handle(WhatsAppClient $client, WhatsAppMessenger $messenger): int
    {
        if (! $client->isEnabled()) {
            $this->error('El envío está desactivado: pon WHATSAPP_ENABLED=true en el .env.');

            return self::FAILURE;
        }

        if (! $client->isConfigured()) {
            $this->error('Falta configuración: '.implode(', ', $client->missingConfiguration()).'.');

            return self::FAILURE;
        }

        $business = null;

        if (filled($this->option('business'))) {
            $business = Business::query()->find((string) $this->option('business'));

            if ($business === null) {
                $this->error('No existe ningún negocio con ese id.');

                return self::FAILURE;
            }
        }

        $to = (string) $this->argument('to');

        try {
            $message = filled($this->option('template'))
                ? $messenger->sendTemplate(
                    $business,
                    $to,
                    (string) $this->option('template'),
                    [],
                    $this->option('language') !== null ? (string) $this->option('language') : null,
                )
                : $messenger->sendText(
                    $business,
                    $to,
                    (string) ($this->option('text') ?? 'Prueba de integración de WhatsApp desde Punto Plus ✅'),
                );
        } catch (WhatsAppException $exception) {
            $this->error('Meta rechazó el envío: '.$exception->getMessage());

            if ($exception->metaCode !== null) {
                $this->line('  code: '.$exception->metaCode.' · subcode: '.($exception->metaSubcode ?? '-'));
            }

            if (($exception->details['missing'] ?? []) !== []) {
                $this->line('  faltan: '.implode(', ', (array) $exception->details['missing']));
            }

            return self::FAILURE;
        }

        $this->info('Mensaje aceptado por WhatsApp Cloud API.');
        $this->line('  id: '.($message->wa_message_id ?? '-'));
        $this->line('  estado: '.$message->status->value.' · destino: '.$message->to_phone);
        $this->line('  Si el webhook está suscrito en Meta, el acuse llegará en unos segundos.');

        return self::SUCCESS;
    }
}
