<?php

namespace Tests\Feature;

use App\Enums\WhatsAppStatus;
use App\Models\WhatsAppMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Webhook de WhatsApp Cloud API: handshake de Meta, mensajes entrantes y
 * acuses de entrega/lectura.
 */
class WhatsAppWebhookTest extends TestCase
{
    use RefreshDatabase;

    private const APP_SECRET = 'app-secret-de-prueba';

    private const VERIFY_TOKEN = 'verify-token-de-prueba';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'whatsapp.enabled' => true,
            'whatsapp.app_secret' => self::APP_SECRET,
            'whatsapp.webhook_verify_token' => self::VERIFY_TOKEN,
        ]);
    }

    protected function tearDown(): void
    {
        config(['whatsapp.app_secret' => null, 'whatsapp.webhook_verify_token' => null]);

        parent::tearDown();
    }

    public function test_valida_el_handshake_de_meta(): void
    {
        $response = $this->get('/api/webhooks/whatsapp?hub.mode=subscribe&hub.verify_token='
            .self::VERIFY_TOKEN.'&hub.challenge=1234567890');

        $response->assertOk();
        $this->assertSame('1234567890', $response->getContent());
    }

    public function test_rechaza_el_handshake_con_token_incorrecto(): void
    {
        $this->get('/api/webhooks/whatsapp?hub.mode=subscribe&hub.verify_token=otro&hub.challenge=123')
            ->assertForbidden()
            ->assertJsonPath('error', 'forbidden');
    }

    public function test_guarda_un_mensaje_entrante(): void
    {
        $response = $this->postWebhook($this->incomingPayload());

        $response->assertOk()->assertJsonPath('messages', 1);

        $this->assertDatabaseHas('whatsapp_messages', [
            'wa_message_id' => 'wamid.ENTRANTE1',
            'direction' => 'incoming',
            'status' => WhatsAppStatus::Received->value,
            'from_phone' => '+5215598765432',
            'to_phone' => '+525512345678',
            'type' => 'text',
            'body' => 'Hola, ¿me faltan sellos?',
        ]);
    }

    public function test_el_mismo_mensaje_entrante_no_se_duplica(): void
    {
        $this->postWebhook($this->incomingPayload())->assertOk();
        $this->postWebhook($this->incomingPayload())->assertOk()->assertJsonPath('messages', 0);

        $this->assertSame(1, WhatsAppMessage::query()->count());
    }

    public function test_aplica_los_acuses_de_entrega_y_lectura(): void
    {
        WhatsAppMessage::factory()->create([
            'wa_message_id' => 'wamid.SALIENTE1',
            'status' => WhatsAppStatus::Sent,
        ]);

        $this->postWebhook($this->statusPayload('delivered'))->assertOk()->assertJsonPath('statuses', 1);

        $this->assertDatabaseHas('whatsapp_messages', [
            'wa_message_id' => 'wamid.SALIENTE1',
            'status' => WhatsAppStatus::Delivered->value,
        ]);

        $this->postWebhook($this->statusPayload('read'))->assertOk();

        $message = WhatsAppMessage::query()->where('wa_message_id', 'wamid.SALIENTE1')->firstOrFail();

        $this->assertSame(WhatsAppStatus::Read, $message->status);
        $this->assertNotNull($message->delivered_at);
        $this->assertNotNull($message->read_at);
    }

    public function test_un_acuse_de_fallo_guarda_el_error_de_meta(): void
    {
        WhatsAppMessage::factory()->create([
            'wa_message_id' => 'wamid.FALLIDO1',
            'status' => WhatsAppStatus::Sent,
        ]);

        $this->postWebhook($this->statusPayload('failed', 'wamid.FALLIDO1', [
            ['code' => 131026, 'title' => 'Message undeliverable'],
        ]))->assertOk();

        $message = WhatsAppMessage::query()->where('wa_message_id', 'wamid.FALLIDO1')->firstOrFail();

        $this->assertSame(WhatsAppStatus::Failed, $message->status);
        $this->assertSame('131026', $message->error_code);
        $this->assertSame('Message undeliverable', $message->error);
    }

    public function test_rechaza_una_firma_invalida(): void
    {
        $this->postWebhook($this->incomingPayload(), 'sha256=firma-falsa')
            ->assertForbidden()
            ->assertJsonPath('error', 'invalid_signature');

        $this->assertDatabaseCount('whatsapp_messages', 0);
    }

    public function test_sin_app_secret_no_procesa_el_webhook(): void
    {
        config(['whatsapp.app_secret' => null]);

        $this->postWebhook($this->incomingPayload())
            ->assertStatus(503)
            ->assertJsonPath('error', 'whatsapp_not_configured');
    }

    // -----------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------

    /**
     * Envía el payload firmado como lo haría Meta.
     *
     * @param  array<string, mixed>  $payload
     */
    private function postWebhook(array $payload, ?string $signature = null): \Illuminate\Testing\TestResponse
    {
        $raw = json_encode($payload, JSON_UNESCAPED_UNICODE);

        $signature ??= 'sha256='.hash_hmac('sha256', (string) $raw, self::APP_SECRET);

        return $this->call('POST', '/api/webhooks/whatsapp', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_HUB_SIGNATURE_256' => $signature,
        ], $raw);
    }

    /**
     * @return array<string, mixed>
     */
    private function incomingPayload(): array
    {
        return [
            'object' => 'whatsapp_business_account',
            'entry' => [[
                'id' => '9876543210',
                'changes' => [[
                    'field' => 'messages',
                    'value' => [
                        'messaging_product' => 'whatsapp',
                        'metadata' => [
                            'display_phone_number' => '52 55 1234 5678',
                            'phone_number_id' => '1234567890',
                        ],
                        'contacts' => [['profile' => ['name' => 'Ana'], 'wa_id' => '5215598765432']],
                        'messages' => [[
                            'from' => '5215598765432',
                            'id' => 'wamid.ENTRANTE1',
                            'timestamp' => '1762000000',
                            'type' => 'text',
                            'text' => ['body' => 'Hola, ¿me faltan sellos?'],
                        ]],
                    ],
                ]],
            ]],
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $errors
     * @return array<string, mixed>
     */
    private function statusPayload(string $status, string $waMessageId = 'wamid.SALIENTE1', array $errors = []): array
    {
        $statusData = [
            'id' => $waMessageId,
            'status' => $status,
            'timestamp' => '1762000100',
            'recipient_id' => '5215598765432',
        ];

        if ($errors !== []) {
            $statusData['errors'] = $errors;
        }

        return [
            'object' => 'whatsapp_business_account',
            'entry' => [[
                'id' => '9876543210',
                'changes' => [[
                    'field' => 'messages',
                    'value' => [
                        'messaging_product' => 'whatsapp',
                        'metadata' => [
                            'display_phone_number' => '52 55 1234 5678',
                            'phone_number_id' => '1234567890',
                        ],
                        'statuses' => [$statusData],
                    ],
                ]],
            ]],
        ];
    }
}
