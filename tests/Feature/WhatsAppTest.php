<?php

namespace Tests\Feature;

use App\Enums\WhatsAppDirection;
use App\Enums\WhatsAppStatus;
use App\Models\Business;
use App\Models\User;
use App\Models\WhatsAppMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\CreatesOAuthClients;
use Tests\TestCase;

/**
 * Envío de WhatsApp desde el área de negocio (Cloud API) e historial.
 */
class WhatsAppTest extends TestCase
{
    use CreatesOAuthClients, RefreshDatabase;

    private User $owner;

    private Business $business;

    protected function setUp(): void
    {
        parent::setUp();

        $this->createOAuthClients();

        config([
            'whatsapp.enabled' => true,
            'whatsapp.access_token' => 'test-token',
            'whatsapp.phone_number_id' => '1234567890',
            'whatsapp.business_account_id' => '9876543210',
            'whatsapp.api_version' => 'v23.0',
            'whatsapp.graph_url' => 'https://graph.facebook.com',
            'whatsapp.default_country_code' => '52',
            'whatsapp.templates.language' => 'es_MX',
        ]);

        Http::preventStrayRequests();

        $this->owner = User::factory()->negocio()->create();
        $this->business = Business::factory()->ownedBy($this->owner)->create();
    }

    public function test_envia_un_texto_y_guarda_el_historial(): void
    {
        Http::fake([
            'graph.facebook.com/*' => Http::response([
                'messaging_product' => 'whatsapp',
                'contacts' => [['input' => '5215512345678', 'wa_id' => '5215512345678']],
                'messages' => [['id' => 'wamid.TEST1', 'message_status' => 'accepted']],
            ]),
        ]);

        $response = $this->actingAsApi($this->owner)->postJson('/api/business/whatsapp/messages', [
            'to' => '5512345678',
            'type' => 'text',
            'body' => '¡Hoy 2x1 en cafés!',
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.status', 'sent')
            ->assertJsonPath('data.to_phone', '+525512345678')
            ->assertJsonPath('data.wa_message_id', 'wamid.TEST1');

        Http::assertSent(function (Request $request): bool {
            return $request->url() === 'https://graph.facebook.com/v23.0/1234567890/messages'
                && $request['messaging_product'] === 'whatsapp'
                && $request['to'] === '+525512345678'
                && $request['type'] === 'text'
                && $request['text']['body'] === '¡Hoy 2x1 en cafés!'
                && $request['text']['preview_url'] === false
                && $request->hasHeader('Authorization', 'Bearer test-token');
        });

        $this->assertDatabaseHas('whatsapp_messages', [
            'wa_message_id' => 'wamid.TEST1',
            'status' => WhatsAppStatus::Sent->value,
            'direction' => WhatsAppDirection::Outgoing->value,
            'business_id' => $this->business->getKey(),
            'to_phone' => '+525512345678',
            'body' => '¡Hoy 2x1 en cafés!',
        ]);
    }

    public function test_envia_una_plantilla_aprobada(): void
    {
        Http::fake([
            'graph.facebook.com/*' => Http::response([
                'messages' => [['id' => 'wamid.TEMPLATE1']],
            ]),
        ]);

        $response = $this->actingAsApi($this->owner)->postJson('/api/business/whatsapp/messages', [
            'to' => '+5215598765432',
            'type' => 'template',
            'template_name' => 'promo_sellos',
            'components' => [
                ['type' => 'body', 'parameters' => [['type' => 'text', 'text' => 'Ana']]],
            ],
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.type', 'template')
            ->assertJsonPath('data.template_name', 'promo_sellos')
            ->assertJsonPath('data.template_language', 'es_MX')
            ->assertJsonPath('data.wa_message_id', 'wamid.TEMPLATE1');

        Http::assertSent(function (Request $request): bool {
            return $request['type'] === 'template'
                && $request['template']['name'] === 'promo_sellos'
                && $request['template']['language']['code'] === 'es_MX'
                && $request['template']['components'][0]['parameters'][0]['text'] === 'Ana';
        });
    }

    public function test_valida_el_destinatario_y_el_cuerpo(): void
    {
        $invalidPhone = $this->actingAsApi($this->owner)->postJson('/api/business/whatsapp/messages', [
            'to' => 'no-es-un-telefono',
            'type' => 'text',
            'body' => 'Hola',
        ]);

        $invalidPhone->assertStatus(422)->assertJsonValidationErrors('to');

        $missingBody = $this->actingAsApi($this->owner)->postJson('/api/business/whatsapp/messages', [
            'to' => '+5215512345678',
            'type' => 'text',
        ]);

        $missingBody->assertStatus(422)->assertJsonValidationErrors('body');

        $badTemplate = $this->actingAsApi($this->owner)->postJson('/api/business/whatsapp/messages', [
            'to' => '+5215512345678',
            'type' => 'template',
            'template_name' => 'Plantilla Inválida',
        ]);

        $badTemplate->assertStatus(422)->assertJsonValidationErrors('template_name');
    }

    public function test_normaliza_los_telefonos_de_diez_digitos(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.NORMALIZADO']]])]);

        $this->actingAsApi($this->owner)->postJson('/api/business/whatsapp/messages', [
            'to' => '55 1234 5678',
            'type' => 'text',
            'body' => 'Hola',
        ])->assertCreated()->assertJsonPath('data.to_phone', '+525512345678');
    }

    public function test_guarda_el_error_de_meta_cuando_rechaza_el_envio(): void
    {
        Http::fake([
            'graph.facebook.com/*' => Http::response([
                'error' => [
                    'message' => '(#131030) Recipient phone number not in allowed list',
                    'type' => 'OAuthException',
                    'code' => 131030,
                ],
            ], 400),
        ]);

        $response = $this->actingAsApi($this->owner)->postJson('/api/business/whatsapp/messages', [
            'to' => '+5215512345678',
            'type' => 'text',
            'body' => 'Hola',
        ]);

        $response->assertStatus(502)
            ->assertJsonPath('error', 'whatsapp_error')
            ->assertJsonPath('code', 131030);

        $this->assertDatabaseHas('whatsapp_messages', [
            'to_phone' => '+5215512345678',
            'status' => WhatsAppStatus::Failed->value,
            'error_code' => '131030',
        ]);
    }

    public function test_sin_credenciales_responde_503(): void
    {
        config(['whatsapp.access_token' => null, 'whatsapp.phone_number_id' => null]);

        $this->actingAsApi($this->owner)->postJson('/api/business/whatsapp/messages', [
            'to' => '+5215512345678',
            'type' => 'text',
            'body' => 'Hola',
        ])
            ->assertStatus(503)
            ->assertJsonPath('error', 'whatsapp_not_configured')
            ->assertJsonPath('missing', ['WHATSAPP_ACCESS_TOKEN', 'WHATSAPP_PHONE_NUMBER_ID']);
    }

    public function test_la_integracion_se_puede_desactivar(): void
    {
        config(['whatsapp.enabled' => false]);

        $this->actingAsApi($this->owner)->postJson('/api/business/whatsapp/messages', [
            'to' => '+5215512345678',
            'type' => 'text',
            'body' => 'Hola',
        ])
            ->assertStatus(503)
            ->assertJsonPath('error', 'whatsapp_disabled');
    }

    public function test_un_cliente_no_puede_enviar_mensajes(): void
    {
        $this->actingAsApi(User::factory()->cliente()->create())
            ->postJson('/api/business/whatsapp/messages', [
                'to' => '+5215512345678',
                'type' => 'text',
                'body' => 'Hola',
            ])
            ->assertForbidden();
    }

    public function test_requiere_autenticacion(): void
    {
        $this->postJson('/api/business/whatsapp/messages', [
            'to' => '+5215512345678',
            'type' => 'text',
            'body' => 'Hola',
        ])->assertUnauthorized();
    }

    public function test_lista_el_historial_del_negocio_con_filtros(): void
    {
        WhatsAppMessage::factory()->for($this->business)->create([
            'to_phone' => '+5215511111111',
            'status' => WhatsAppStatus::Delivered,
        ]);
        WhatsAppMessage::factory()->for($this->business)->incoming()->create([
            'from_phone' => '+5215522222222',
            'body' => '¿Todavía tengo sellos?',
        ]);
        WhatsAppMessage::factory()->create(); // otro negocio

        $response = $this->actingAsApi($this->owner)->getJson('/api/business/whatsapp/messages');

        $response->assertOk()->assertJsonCount(2, 'data');

        $incoming = $this->actingAsApi($this->owner)
            ->getJson('/api/business/whatsapp/messages?direction=incoming');

        $incoming->assertOk()->assertJsonCount(1, 'data');

        $search = $this->actingAsApi($this->owner)
            ->getJson('/api/business/whatsapp/messages?search=sellos');

        $search->assertOk()->assertJsonCount(1, 'data');

        $delivered = $this->actingAsApi($this->owner)
            ->getJson('/api/business/whatsapp/messages?status=delivered');

        $delivered->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_lista_las_plantillas_de_la_cuenta_de_whatsapp(): void
    {
        Http::fake([
            'graph.facebook.com/*' => Http::response([
                'data' => [
                    [
                        'id' => '111',
                        'name' => 'promo_sellos',
                        'status' => 'APPROVED',
                        'category' => 'MARKETING',
                        'language' => 'es_MX',
                        'components' => [['type' => 'BODY', 'text' => 'Hola {{1}}']],
                    ],
                ],
            ]),
        ]);

        $response = $this->actingAsApi($this->owner)->getJson('/api/business/whatsapp/templates');

        $response->assertOk()
            ->assertJsonPath('data.0.name', 'promo_sellos')
            ->assertJsonPath('data.0.status', 'APPROVED');

        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/9876543210/message_templates'));
    }
}
