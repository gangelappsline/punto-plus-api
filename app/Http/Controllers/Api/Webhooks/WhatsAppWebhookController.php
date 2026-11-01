<?php

namespace App\Http\Controllers\Api\Webhooks;

use App\Http\Controllers\Controller;
use App\Services\WhatsApp\WhatsAppClient;
use App\Services\WhatsApp\WhatsAppMessenger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Webhook de WhatsApp Cloud API.
 *
 * - `GET`  : handshake de Meta (`hub.mode=subscribe` + `hub.verify_token`).
 * - `POST` : mensajes entrantes y acuses de entrega/lectura. Meta firma cada
 *            petición con `X-Hub-Signature-256` usando el App Secret.
 *
 * Meta reintenta mientras no reciba un 200, así que el POST siempre responde
 * 200 cuando el payload se procesa (aunque no traiga nada que guardar).
 */
class WhatsAppWebhookController extends Controller
{
    public function __construct(
        private readonly WhatsAppClient $client,
        private readonly WhatsAppMessenger $messenger,
    ) {
    }

    /**
     * GET /api/webhooks/whatsapp
     */
    public function verify(Request $request): Response
    {
        $mode = $request->query('hub.mode', $request->query('hub_mode'));
        $token = $request->query('hub.verify_token', $request->query('hub_verify_token'));
        $challenge = $request->query('hub.challenge', $request->query('hub_challenge'));

        if ($mode !== 'subscribe') {
            return $this->denied('Falta hub.mode=subscribe en la verificación del webhook.');
        }

        if (! $this->client->verifyWebhookToken(is_string($token) ? $token : null)) {
            return $this->denied('El token de verificación del webhook no coincide.');
        }

        return response((string) $challenge, 200)
            ->header('Content-Type', 'text/plain');
    }

    /**
     * POST /api/webhooks/whatsapp
     */
    public function receive(Request $request): JsonResponse
    {
        $rawBody = $request->getContent();

        if (! $this->client->hasAppSecret()) {
            return response()->json([
                'message' => 'Falta WHATSAPP_APP_SECRET para poder verificar la firma del webhook.',
                'error' => 'whatsapp_not_configured',
                'missing' => ['WHATSAPP_APP_SECRET'],
            ], 503);
        }

        if (! $this->client->verifySignature($rawBody, $request->header('X-Hub-Signature-256'))) {
            Log::warning('WhatsApp: firma de webhook inválida', ['ip' => $request->ip()]);

            return response()->json([
                'message' => 'La firma del webhook no es válida.',
                'error' => 'invalid_signature',
            ], 403);
        }

        $payload = json_decode($rawBody, true);

        if (! is_array($payload)) {
            return response()->json([
                'message' => 'El cuerpo del webhook no es un JSON válido.',
                'error' => 'invalid_payload',
            ], 400);
        }

        $counts = $this->messenger->handleWebhook($payload);

        Log::info('WhatsApp: webhook procesado', $counts);

        return response()->json(['received' => true] + $counts);
    }

    private function denied(string $message): JsonResponse
    {
        return response()->json([
            'message' => $message,
            'error' => 'forbidden',
        ], 403);
    }
}
