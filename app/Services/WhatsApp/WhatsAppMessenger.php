<?php

namespace App\Services\WhatsApp;

use App\Enums\WhatsAppDirection;
use App\Enums\WhatsAppStatus;
use App\Exceptions\WhatsAppException;
use App\Models\Business;
use App\Models\WhatsAppMessage;
use App\Support\PhoneNumber;
use Closure;
use Illuminate\Support\Carbon;

/**
 * Orquesta el envío de WhatsApp: valida el destinatario, guarda el mensaje en
 * `whatsapp_messages`, llama a la Cloud API y deja el resultado (id de Meta o
 * error) en el registro.
 *
 * También procesa lo que llega por webhook: mensajes de los clientes y acuses
 * de entrega/lectura.
 */
final class WhatsAppMessenger
{
    public function __construct(private readonly WhatsAppClient $client)
    {
    }

    // -----------------------------------------------------------------
    // Salida
    // -----------------------------------------------------------------

    public function sendText(?Business $business, string $to, string $body, bool $previewUrl = false): WhatsAppMessage
    {
        $message = $this->newOutgoing($business, $to, type: 'text', body: $body, request: [
            'type' => 'text',
            'text' => ['preview_url' => $previewUrl, 'body' => $body],
        ]);

        return $this->dispatch(
            $message,
            fn (): array => $this->client->sendText((string) $message->to_phone, $body, $previewUrl),
        );
    }

    /**
     * @param  array<int, array<string, mixed>>  $components
     */
    public function sendTemplate(
        ?Business $business,
        string $to,
        string $name,
        array $components = [],
        ?string $language = null,
    ): WhatsAppMessage {
        $language = $language ?? (string) config('whatsapp.templates.language', 'es_MX');

        $message = $this->newOutgoing($business, $to, type: 'template', body: null, request: [
            'type' => 'template',
            'template' => [
                'name' => $name,
                'language' => ['code' => $language],
                'components' => $components,
            ],
        ], templateName: $name, templateLanguage: $language);

        return $this->dispatch(
            $message,
            fn (): array => $this->client->sendTemplate((string) $message->to_phone, $name, $components, $language),
        );
    }

    /**
     * @param  Closure(): array<string, mixed>  $send
     */
    private function dispatch(WhatsAppMessage $message, Closure $send): WhatsAppMessage
    {
        try {
            $response = $send();
        } catch (WhatsAppException $exception) {
            $message->markAsFailed(
                $exception,
                $exception->metaCode !== null ? (string) $exception->metaCode : 'whatsapp_error',
            );

            throw $exception;
        }

        return $message->markAsSent($response);
    }

    /**
     * @param  array<string, mixed>  $request
     */
    private function newOutgoing(
        ?Business $business,
        string $to,
        string $type,
        ?string $body,
        array $request,
        ?string $templateName = null,
        ?string $templateLanguage = null,
    ): WhatsAppMessage {
        $normalized = PhoneNumber::normalize($to, config('whatsapp.default_country_code'));

        if ($normalized === null) {
            throw WhatsAppException::invalidRecipient($to);
        }

        return WhatsAppMessage::create([
            'direction' => WhatsAppDirection::Outgoing,
            'status' => WhatsAppStatus::Queued,
            'business_id' => $business?->getKey(),
            'from_phone' => null,
            'to_phone' => $normalized,
            'type' => $type,
            'template_name' => $templateName,
            'template_language' => $templateLanguage,
            'body' => $body,
            'payload' => $request,
        ]);
    }

    // -----------------------------------------------------------------
    // Webhook
    // -----------------------------------------------------------------

    /**
     * Procesa el payload de un webhook de WhatsApp (mensajes y acuses).
     *
     * @param  array<string, mixed>  $payload
     * @return array{messages: int, statuses: int}
     */
    public function handleWebhook(array $payload): array
    {
        $counts = ['messages' => 0, 'statuses' => 0];

        if (($payload['object'] ?? null) !== 'whatsapp_business_account') {
            return $counts;
        }

        foreach ((array) ($payload['entry'] ?? []) as $entry) {
            foreach ((array) (($entry['changes'] ?? [])) as $change) {
                if (($change['field'] ?? null) !== 'messages') {
                    continue;
                }

                $value = (array) ($change['value'] ?? []);

                $counts['messages'] += $this->storeIncomingMessages($value);
                $counts['statuses'] += $this->applyStatuses($value);
            }
        }

        return $counts;
    }

    /**
     * @param  array<string, mixed>  $value
     */
    private function storeIncomingMessages(array $value): int
    {
        $stored = 0;

        foreach ((array) ($value['messages'] ?? []) as $message) {
            $waMessageId = (string) ($message['id'] ?? '');

            if ($waMessageId === '') {
                continue;
            }

            $record = WhatsAppMessage::query()->firstOrCreate(
                ['wa_message_id' => $waMessageId],
                [
                    'direction' => WhatsAppDirection::Incoming,
                    'status' => WhatsAppStatus::Received,
                    'from_phone' => $this->plusDigits($message['from'] ?? null),
                    'to_phone' => $this->businessPhone($value),
                    'type' => $this->typeOf($message),
                    'body' => $this->extractBody($message),
                    'payload' => $message,
                    'sent_at' => $this->timestamp($message['timestamp'] ?? null),
                ],
            );

            if ($record->wasRecentlyCreated) {
                $stored++;
            }
        }

        return $stored;
    }

    /**
     * @param  array<string, mixed>  $value
     */
    private function applyStatuses(array $value): int
    {
        $applied = 0;

        foreach ((array) ($value['statuses'] ?? []) as $status) {
            $waMessageId = (string) ($status['id'] ?? '');
            $mapped = WhatsAppStatus::fromMeta(
                is_string($status['status'] ?? null) ? $status['status'] : null,
            );

            if ($waMessageId === '' || $mapped === null) {
                continue;
            }

            $record = WhatsAppMessage::query()->firstOrNew(['wa_message_id' => $waMessageId]);

            if (! $record->exists) {
                // Acuse de un mensaje enviado antes de activar el historial.
                $record->direction = WhatsAppDirection::Outgoing;
                $record->status = WhatsAppStatus::Queued;
                $record->from_phone = $this->businessPhone($value);
                $record->to_phone = $this->plusDigits($status['recipient_id'] ?? null);
                $record->type = 'unknown';
            }

            if ($mapped === WhatsAppStatus::Failed) {
                $record->error_code = $this->stringOrNull(data_get($status, 'errors.0.code'));
                $record->error = $this->stringOrNull(
                    data_get($status, 'errors.0.title') ?? data_get($status, 'errors.0.message'),
                );
            }

            $record->applyStatus($mapped, $this->timestamp($status['timestamp'] ?? null));
            $record->payload = array_merge(
                (array) $record->payload,
                ['status' => $status],
            );
            $record->save();

            $applied++;
        }

        return $applied;
    }

    // -----------------------------------------------------------------
    // Helpers de parseo
    // -----------------------------------------------------------------

    /**
     * @param  array<string, mixed>  $value
     */
    private function businessPhone(array $value): ?string
    {
        return $this->plusDigits(data_get($value, 'metadata.display_phone_number'));
    }

    private function plusDigits(mixed $phone): ?string
    {
        $digits = preg_replace('/\D+/', '', (string) $phone);

        return $digits === '' || $digits === null ? null : '+'.$digits;
    }

    /**
     * @param  array<string, mixed>  $message
     */
    private function typeOf(array $message): string
    {
        $type = (string) ($message['type'] ?? 'unknown');

        return $type === '' ? 'unknown' : substr($type, 0, 30);
    }

    /**
     * Texto representativo del mensaje entrante (los adjuntos se resumen).
     *
     * @param  array<string, mixed>  $message
     */
    private function extractBody(array $message): ?string
    {
        $body = match ((string) ($message['type'] ?? '')) {
            'text' => data_get($message, 'text.body'),
            'button' => data_get($message, 'button.text'),
            'interactive' => data_get($message, 'interactive.button_reply.title')
                ?? data_get($message, 'interactive.list_reply.title'),
            'image' => data_get($message, 'image.caption') ?? '[imagen]',
            'video' => data_get($message, 'video.caption') ?? '[video]',
            'document' => data_get($message, 'document.caption')
                ?? data_get($message, 'document.filename')
                ?? '[documento]',
            'audio' => '[audio]',
            'sticker' => '[sticker]',
            'location' => data_get($message, 'location.name')
                ?? data_get($message, 'location.address')
                ?? '[ubicación]',
            'contacts' => '[contacto]',
            'reaction' => data_get($message, 'reaction.emoji'),
            default => null,
        };

        $body = $this->stringOrNull($body);

        return $body === null ? null : mb_substr($body, 0, 4096);
    }

    private function timestamp(mixed $value): Carbon
    {
        return is_numeric($value)
            ? Carbon::createFromTimestamp((int) $value)
            : Carbon::now();
    }

    private function stringOrNull(mixed $value): ?string
    {
        if (is_array($value)) {
            $value = $value['title'] ?? $value['message'] ?? null;
        }

        $value = is_scalar($value) ? trim((string) $value) : '';

        return $value === '' ? null : $value;
    }
}
