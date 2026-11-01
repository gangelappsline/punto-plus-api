<?php

namespace App\Services\WhatsApp;

use App\Exceptions\WhatsAppException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Cliente mínimo de WhatsApp Cloud API (Meta).
 *
 * Sólo hace las llamadas HTTP: quién guarda el historial y cómo se comporta la
 * API ante los errores lo decide `WhatsAppMessenger` / los controladores.
 *
 * @see https://developers.facebook.com/docs/whatsapp/cloud-api
 */
final class WhatsAppClient
{
    private readonly ?string $accessToken;

    private readonly ?string $phoneNumberId;

    private readonly ?string $businessAccountId;

    private readonly string $apiVersion;

    private readonly string $graphUrl;

    private readonly ?string $appSecret;

    private readonly ?string $webhookVerifyToken;

    private readonly int $timeoutSeconds;

    private readonly int $connectTimeoutSeconds;

    private readonly int $retryTimes;

    private readonly int $retrySleepMs;

    public function __construct(
        ?string $accessToken = null,
        ?string $phoneNumberId = null,
        ?string $businessAccountId = null,
        ?string $apiVersion = null,
        ?string $graphUrl = null,
        ?string $appSecret = null,
        ?string $webhookVerifyToken = null,
        ?int $timeoutSeconds = null,
        ?int $connectTimeoutSeconds = null,
        ?int $retryTimes = null,
        ?int $retrySleepMs = null,
    ) {
        $this->accessToken = $accessToken ?? self::configString('whatsapp.access_token');
        $this->phoneNumberId = $phoneNumberId ?? self::configString('whatsapp.phone_number_id');
        $this->businessAccountId = $businessAccountId ?? self::configString('whatsapp.business_account_id');
        $this->apiVersion = $apiVersion ?? self::configString('whatsapp.api_version') ?? 'v23.0';
        $this->graphUrl = rtrim($graphUrl ?? self::configString('whatsapp.graph_url') ?? 'https://graph.facebook.com', '/');
        $this->appSecret = $appSecret ?? self::configString('whatsapp.app_secret');
        $this->webhookVerifyToken = $webhookVerifyToken ?? self::configString('whatsapp.webhook_verify_token');
        $this->timeoutSeconds = $timeoutSeconds ?? (int) config('whatsapp.timeout_seconds', 15);
        $this->connectTimeoutSeconds = $connectTimeoutSeconds ?? (int) config('whatsapp.connect_timeout_seconds', 5);
        $this->retryTimes = $retryTimes ?? (int) config('whatsapp.retry_times', 2);
        $this->retrySleepMs = $retrySleepMs ?? (int) config('whatsapp.retry_sleep_ms', 300);
    }

    // -----------------------------------------------------------------
    // Estado de la configuración
    // -----------------------------------------------------------------

    public function isEnabled(): bool
    {
        return (bool) config('whatsapp.enabled');
    }

    public function isConfigured(): bool
    {
        return $this->missingConfiguration() === [];
    }

    /**
     * Variables de entorno que faltan para poder enviar.
     *
     * @return array<int, string>
     */
    public function missingConfiguration(): array
    {
        $missing = [];

        if ($this->accessToken === null) {
            $missing[] = 'WHATSAPP_ACCESS_TOKEN';
        }

        if ($this->phoneNumberId === null) {
            $missing[] = 'WHATSAPP_PHONE_NUMBER_ID';
        }

        return $missing;
    }

    public function phoneNumberId(): ?string
    {
        return $this->phoneNumberId;
    }

    public function businessAccountId(): ?string
    {
        return $this->businessAccountId;
    }

    // -----------------------------------------------------------------
    // Envío
    // -----------------------------------------------------------------

    /**
     * Mensaje de texto libre. Sólo se puede enviar dentro de las 24 h
     * posteriores al último mensaje del cliente (si no, Meta exige plantilla).
     *
     * @return array<string, mixed>
     */
    public function sendText(string $to, string $body, bool $previewUrl = false): array
    {
        return $this->send([
            'messaging_product' => 'whatsapp',
            'recipient_type' => 'individual',
            'to' => $to,
            'type' => 'text',
            'text' => [
                'preview_url' => $previewUrl,
                'body' => $body,
            ],
        ]);
    }

    /**
     * Plantilla aprobada por Meta (obligatoria fuera de la ventana de 24 h).
     *
     * @param  array<int, array<string, mixed>>  $components
     * @return array<string, mixed>
     */
    public function sendTemplate(string $to, string $name, array $components = [], ?string $language = null): array
    {
        $template = [
            'name' => $name,
            'language' => ['code' => $language ?? self::configString('whatsapp.templates.language') ?? 'es_MX'],
        ];

        if ($components !== []) {
            $template['components'] = array_values($components);
        }

        return $this->send([
            'messaging_product' => 'whatsapp',
            'recipient_type' => 'individual',
            'to' => $to,
            'type' => 'template',
            'template' => $template,
        ]);
    }

    /**
     * Marca como leído un mensaje del cliente (doble palomino azul).
     *
     * @return array<string, mixed>
     */
    public function markAsRead(string $waMessageId): array
    {
        return $this->send([
            'messaging_product' => 'whatsapp',
            'status' => 'read',
            'message_id' => $waMessageId,
        ]);
    }

    /**
     * Plantillas de la WABA con su estado de aprobación.
     *
     * @return array<int, array<string, mixed>>
     */
    public function templates(int $limit = 100): array
    {
        if ($this->businessAccountId === null) {
            throw WhatsAppException::notConfigured(['WHATSAPP_BUSINESS_ACCOUNT_ID']);
        }

        try {
            $response = $this->http()->get($this->url($this->businessAccountId.'/message_templates'), [
                'limit' => $limit,
                'fields' => 'id,name,status,category,language,components',
            ]);
        } catch (ConnectionException $exception) {
            throw new WhatsAppException('No se pudo conectar con WhatsApp Cloud API.', previous: $exception);
        }

        if ($response->failed()) {
            throw WhatsAppException::fromResponse($response);
        }

        $data = $response->json('data');

        return is_array($data) ? $data : [];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function send(array $payload): array
    {
        if (! $this->isConfigured()) {
            throw WhatsAppException::notConfigured($this->missingConfiguration());
        }

        try {
            $response = $this->http()->post($this->url($this->phoneNumberId.'/messages'), $payload);
        } catch (ConnectionException $exception) {
            throw new WhatsAppException('No se pudo conectar con WhatsApp Cloud API.', previous: $exception);
        }

        if ($response->failed()) {
            throw WhatsAppException::fromResponse($response);
        }

        return $response->json() ?? [];
    }

    // -----------------------------------------------------------------
    // Webhooks
    // -----------------------------------------------------------------

    /**
     * Comprueba el token del handshake `GET /webhook` de Meta.
     */
    public function verifyWebhookToken(?string $token): bool
    {
        return $this->webhookVerifyToken !== null
            && is_string($token)
            && hash_equals($this->webhookVerifyToken, $token);
    }

    /**
     * Valida la cabecera `X-Hub-Signature-256` con el App Secret de Meta.
     */
    public function verifySignature(string $rawBody, ?string $signature): bool
    {
        if ($this->appSecret === null || ! is_string($signature) || $signature === '') {
            return false;
        }

        $expected = 'sha256='.hash_hmac('sha256', $rawBody, $this->appSecret);

        return hash_equals($expected, $signature);
    }

    public function hasAppSecret(): bool
    {
        return $this->appSecret !== null;
    }

    // -----------------------------------------------------------------
    // Interno
    // -----------------------------------------------------------------

    private function http(): PendingRequest
    {
        return Http::withToken((string) $this->accessToken)
            ->acceptJson()
            ->asJson()
            ->timeout($this->timeoutSeconds)
            ->connectTimeout($this->connectTimeoutSeconds)
            ->retry(
                $this->retryTimes,
                $this->retrySleepMs,
                // Un 4xx de Meta (token, plantilla, número no permitido) no se
                // arregla repitiendo: sólo se reintenta conexión, 5xx y 429.
                fn (Throwable $exception): bool => $exception instanceof ConnectionException
                    || ($exception instanceof RequestException
                        && ($exception->response?->serverError() || $exception->response?->status() === 429)),
                throw: false,
            );
    }

    private function url(string $path): string
    {
        return $this->graphUrl.'/'.trim($this->apiVersion, '/').'/'.ltrim($path, '/');
    }

    private static function configString(string $key): ?string
    {
        $value = config($key);

        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }
}
