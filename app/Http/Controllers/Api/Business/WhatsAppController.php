<?php

namespace App\Http\Controllers\Api\Business;

use App\Enums\WhatsAppDirection;
use App\Enums\WhatsAppStatus;
use App\Exceptions\WhatsAppException;
use App\Http\Controllers\Controller;
use App\Http\Requests\WhatsApp\SendWhatsAppMessageRequest;
use App\Http\Resources\WhatsAppMessageResource;
use App\Models\WhatsAppMessage;
use App\Services\WhatsApp\WhatsAppClient;
use App\Services\WhatsApp\WhatsAppMessenger;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

/**
 * Envío de mensajes de WhatsApp desde el negocio (WhatsApp Cloud API).
 *
 * El historial (`GET whatsapp/messages`) mezcla los envíos propios, los
 * mensajes entrantes de los clientes y los acuses de entrega/lectura que
 * llegan por webhook.
 */
class WhatsAppController extends Controller
{
    public function __construct(
        private readonly WhatsAppClient $client,
        private readonly WhatsAppMessenger $messenger,
    ) {
    }

    /**
     * GET /api/business/whatsapp/messages
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $filters = $request->validate([
            'direction' => ['nullable', Rule::enum(WhatsAppDirection::class)],
            'status' => ['nullable', Rule::enum(WhatsAppStatus::class)],
            'search' => ['nullable', 'string', 'max:120'],
            'business_id' => ['nullable', 'uuid'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:'.config('punto_plus.per_page.max')],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);

        $query = WhatsAppMessage::query();

        if ($request->user()->isAdmin()) {
            // Un admin puede ver el historial global o filtrar por negocio.
            $query->when(
                filled($filters['business_id'] ?? null),
                fn (Builder $query): Builder => $query->forBusiness($filters['business_id']),
            );
        } else {
            $business = $request->user()->business()->first();

            abort_if($business === null, 404, 'Tu usuario no tiene un negocio asociado.');

            $query->forBusiness($business);
        }

        $messages = $query
            ->direction($filters['direction'] ?? null)
            ->status($filters['status'] ?? null)
            ->search($filters['search'] ?? null)
            ->orderByDesc('created_at')
            ->paginate($this->perPage($request))
            ->withQueryString();

        return WhatsAppMessageResource::collection($messages);
    }

    /**
     * POST /api/business/whatsapp/messages
     *
     * Ejemplos de cuerpo:
     *   {"to": "+5215512345678", "type": "text", "body": "¡Hoy 2x1 en cafés!"}
     *   {"to": "5512345678", "type": "template", "template_name": "promo_sellos",
     *    "template_language": "es_MX", "components": [{"type": "body", "parameters": [{"type": "text", "text": "Ana"}]}]}
     */
    public function send(SendWhatsAppMessageRequest $request): JsonResponse
    {
        if ($unavailable = $this->unavailable()) {
            return $unavailable;
        }

        $data = $request->validated();
        $business = $request->user()->business()->first();

        try {
            $message = $data['type'] === 'template'
                ? $this->messenger->sendTemplate(
                    $business,
                    (string) $data['to'],
                    (string) $data['template_name'],
                    (array) ($data['components'] ?? []),
                    $data['template_language'] ?? null,
                )
                : $this->messenger->sendText(
                    $business,
                    (string) $data['to'],
                    (string) $data['body'],
                    (bool) ($data['preview_url'] ?? false),
                );
        } catch (WhatsAppException $exception) {
            return $this->whatsappError($exception);
        }

        return (new WhatsAppMessageResource($message))
            ->response()
            ->setStatusCode(201);
    }

    /**
     * GET /api/business/whatsapp/templates
     *
     * Plantillas de la WABA con su estado de aprobación, para que la app/panel
     * sepa qué puede enviar fuera de la ventana de 24 h.
     */
    public function templates(): JsonResponse
    {
        if ($unavailable = $this->unavailable()) {
            return $unavailable;
        }

        try {
            $templates = $this->client->templates();
        } catch (WhatsAppException $exception) {
            return $this->whatsappError($exception);
        }

        return response()->json([
            'data' => array_map(static fn (array $template): array => [
                'id' => $template['id'] ?? null,
                'name' => $template['name'] ?? null,
                'status' => $template['status'] ?? null,
                'category' => $template['category'] ?? null,
                'language' => $template['language'] ?? null,
                'components' => $template['components'] ?? [],
            ], $templates),
        ]);
    }

    /**
     * Respuesta 503 si la integración está apagada o sin credenciales.
     */
    private function unavailable(): ?JsonResponse
    {
        if (! $this->client->isEnabled()) {
            return response()->json([
                'message' => 'El envío de WhatsApp está desactivado (WHATSAPP_ENABLED=false).',
                'error' => 'whatsapp_disabled',
            ], 503);
        }

        $missing = $this->client->missingConfiguration();

        if ($missing !== []) {
            return response()->json([
                'message' => 'WhatsApp Cloud API no está configurado. Faltan: '.implode(', ', $missing).'.',
                'error' => 'whatsapp_not_configured',
                'missing' => $missing,
            ], 503);
        }

        return null;
    }

    /**
     * Error de Meta: 503 si es de configuración, 502 si lo rechazó la Cloud API.
     */
    private function whatsappError(WhatsAppException $exception): JsonResponse
    {
        $missing = (array) ($exception->details['missing'] ?? []);

        $payload = [
            'message' => $exception->getMessage(),
            'error' => $missing === [] ? 'whatsapp_error' : 'whatsapp_not_configured',
        ];

        if ($exception->metaCode !== null) {
            $payload['code'] = $exception->metaCode;
        }

        if ($exception->metaSubcode !== null) {
            $payload['subcode'] = $exception->metaSubcode;
        }

        if ($exception->metaType !== null) {
            $payload['type'] = $exception->metaType;
        }

        if ($missing !== []) {
            $payload['missing'] = $missing;
        }

        return response()->json($payload, $missing === [] ? 502 : 503);
    }
}
