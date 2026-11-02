<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\CustomerCard;
use App\Models\LoyaltyCard;
use App\Models\SaleToken;
use App\Models\Stamp;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesOAuthClients;
use Tests\TestCase;

/**
 * Tokens de venta: el negocio genera un código de 5 minutos y el cliente lo
 * valida para que la compra cuente en su tarjeta.
 */
class SaleTokenTest extends TestCase
{
    use CreatesOAuthClients, RefreshDatabase;

    private User $owner;

    private Business $business;

    private LoyaltyCard $card;

    private User $cliente;

    protected function setUp(): void
    {
        parent::setUp();

        $this->createOAuthClients();

        // Varias ventas seguidas en la suite: sin espera anti-fraude entre sellos.
        config(['punto_plus.cards.stamp_throttle_seconds' => 0]);

        $this->owner = User::factory()->negocio()->create();
        $this->business = Business::factory()->ownedBy($this->owner)->create();
        $this->card = LoyaltyCard::factory()->forBusiness($this->business)->create([
            'required_stamps' => 3,
            'join_code' => 'VENTAS01',
        ]);

        $this->cliente = User::factory()->cliente()->create();
    }

    public function test_el_negocio_genera_un_token_con_qr_y_cinco_minutos_de_vida(): void
    {
        $response = $this->actingAsApi($this->owner)->getJson('/api/business/new-token');

        $response->assertCreated()
            ->assertJsonPath('data.loyalty_card_id', $this->card->getKey())
            ->assertJsonPath('data.ttl_minutes', 5)
            ->assertJsonPath('data.is_expired', false)
            ->assertJsonPath('data.qr.payload.type', 'sale_token.redeem')
            ->assertJsonPath('data.qr.payload.code', $response->json('data.code'));

        $code = (string) $response->json('data.code');
        $expiresIn = (int) $response->json('data.expires_in_seconds');

        $this->assertSame(8, strlen($code));
        $this->assertSame($code, strtoupper($code));
        $this->assertStringContainsString('-', (string) $response->json('data.display_code'));
        $this->assertGreaterThan(240, $expiresIn);
        $this->assertLessThanOrEqual(300, $expiresIn);

        $payload = json_decode((string) $response->json('data.qr_content'), true);
        $this->assertSame($code, $payload['code'] ?? null);

        $this->assertDatabaseHas('sale_tokens', ['id' => $response->json('data.id'), 'code' => $code]);
    }

    public function test_el_token_necesita_una_tarjeta_activa_y_desambigua_cuando_hay_varias(): void
    {
        $sinTarjetas = Business::factory()->ownedBy(User::factory()->negocio()->create())->create();

        $this->actingAsApi($sinTarjetas->owner)
            ->getJson('/api/business/new-token')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('loyalty_card_id');

        // Segunda tarjeta activa: hay que decir a cuál aplica el token.
        $segunda = LoyaltyCard::factory()->forBusiness($this->business)->create();

        $this->actingAsApi($this->owner)
            ->getJson('/api/business/new-token')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('loyalty_card_id');

        $this->actingAsApi($this->owner)
            ->getJson('/api/business/new-token?loyalty_card_id='.$segunda->getKey())
            ->assertCreated()
            ->assertJsonPath('data.loyalty_card_id', $segunda->getKey());

        // Una tarjeta de otro negocio no se puede usar.
        $ajena = LoyaltyCard::factory()->create();

        $this->actingAsApi($this->owner)
            ->getJson('/api/business/new-token?loyalty_card_id='.$ajena->getKey())
            ->assertUnprocessable()
            ->assertJsonValidationErrors('loyalty_card_id');
    }

    public function test_el_cliente_valida_el_token_y_suma_una_venta_a_su_tarjeta(): void
    {
        $customerCard = CustomerCard::factory()->forUser($this->cliente)->forCard($this->card)->create();

        // El token lo genera el negocio desde su panel.
        $issued = $this->actingAsApi($this->owner)->getJson('/api/business/new-token');
        $issued->assertCreated();

        $code = (string) $issued->json('data.code');
        $tokenId = (string) $issued->json('data.id');

        // Se acepta el código con guion y en minúsculas (como lo teclea el cliente).
        $response = $this->actingAsApi($this->cliente)->postJson('/api/customer/validate-token', [
            'token' => strtolower(substr($code, 0, 4).'-'.substr($code, 4)),
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.source', 'token')
            ->assertJsonPath('data.source_label', 'Token de venta')
            ->assertJsonPath('customer_card.stamps_count', 1)
            ->assertJsonPath('sale_token.code', $code);

        $this->assertSame(1, (int) $customerCard->refresh()->stamps_count);
        $this->assertDatabaseMissing('sale_tokens', ['id' => $tokenId]);

        $stamp = Stamp::where('customer_card_id', $customerCard->getKey())->firstOrFail();
        $this->assertSame($code, $stamp->meta['sale_token_code'] ?? null);
        $this->assertSame($this->owner->getKey(), (int) $stamp->registered_by);
    }

    public function test_el_cliente_se_une_al_programa_si_todavia_no_tenia_tarjeta(): void
    {
        $token = SaleToken::factory()->forLoyaltyCard($this->card)->create();

        $this->actingAsApi($this->cliente)
            ->postJson('/api/customer/validate-token', ['token' => $token->code])
            ->assertCreated()
            ->assertJsonPath('customer_card.stamps_count', 1);

        $this->assertDatabaseHas('customer_cards', [
            'user_id' => $this->cliente->getKey(),
            'loyalty_card_id' => $this->card->getKey(),
            'stamps_count' => 1,
        ]);
    }

    public function test_tambien_se_acepta_el_alias_code_y_falta_el_token_responde_422(): void
    {
        $token = SaleToken::factory()->forLoyaltyCard($this->card)->create();

        $this->actingAsApi($this->cliente)
            ->postJson('/api/customer/validate-token', ['code' => $token->code])
            ->assertCreated()
            ->assertJsonPath('data.source', 'token');

        // Sin `token` ni `code` el error llega en la clave `token`.
        $this->actingAsApi($this->cliente)
            ->postJson('/api/customer/validate-token', [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('token');
    }

    public function test_un_token_caducado_responde_422_y_se_elimina(): void
    {
        $token = SaleToken::factory()->forLoyaltyCard($this->card)->expired()->create();

        $this->actingAsApi($this->cliente)
            ->postJson('/api/customer/validate-token', ['token' => $token->code])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('token');

        $this->assertDatabaseMissing('sale_tokens', ['id' => $token->getKey()]);
        $this->assertDatabaseCount('stamps', 0);
    }

    public function test_un_token_no_se_puede_validar_dos_veces(): void
    {
        $token = SaleToken::factory()->forLoyaltyCard($this->card)->create();

        $this->actingAsApi($this->cliente)
            ->postJson('/api/customer/validate-token', ['token' => $token->code])
            ->assertCreated();

        $this->actingAsApi($this->cliente)
            ->postJson('/api/customer/validate-token', ['token' => $token->code])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('token');

        $this->assertDatabaseCount('stamps', 1);
    }

    public function test_el_negocio_no_puede_validar_sus_propios_tokens(): void
    {
        $token = SaleToken::factory()->forLoyaltyCard($this->card)->create();

        $this->actingAsApi($this->owner)
            ->postJson('/api/customer/validate-token', ['token' => $token->code])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('token');

        // El token sigue vivo para que lo use un cliente real.
        $this->assertDatabaseHas('sale_tokens', ['id' => $token->getKey()]);
    }

    public function test_una_tarjeta_por_consumo_registra_los_puntos_del_monto(): void
    {
        $spendCard = LoyaltyCard::factory()->forBusiness($this->business)->spend(100, 10)->create();
        CustomerCard::factory()->forUser($this->cliente)->forCard($spendCard)->create();

        $token = SaleToken::factory()->forLoyaltyCard($spendCard)->create(['purchase_amount' => 250]);

        $this->actingAsApi($this->cliente)
            ->postJson('/api/customer/validate-token', ['token' => $token->code])
            ->assertCreated()
            ->assertJsonPath('data.points_awarded', 20)
            ->assertJsonPath('customer_card.points_count', 20);
    }

    public function test_un_programa_privado_no_se_une_solo_con_el_token(): void
    {
        $this->card->update(['is_public' => false]);

        $token = SaleToken::factory()->forLoyaltyCard($this->card)->create();

        $this->actingAsApi($this->cliente)
            ->postJson('/api/customer/validate-token', ['token' => $token->code])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('token');

        $this->assertDatabaseCount('customer_cards', 0);
        $this->assertDatabaseCount('stamps', 0);
    }

    public function test_el_panel_lista_y_cancela_los_tokens_vivos(): void
    {
        $token = SaleToken::factory()->forLoyaltyCard($this->card)->create();

        $this->actingAsApi($this->owner)
            ->getJson('/api/business/tokens')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.code', $token->code);

        $this->actingAsApi($this->owner)
            ->deleteJson('/api/business/tokens/'.$token->getKey())
            ->assertOk();

        $this->assertDatabaseMissing('sale_tokens', ['id' => $token->getKey()]);
    }

    public function test_otro_negocio_no_puede_cancelar_un_token_ajeno(): void
    {
        $token = SaleToken::factory()->forLoyaltyCard($this->card)->create();
        $otherOwner = User::factory()->negocio()->create();
        Business::factory()->ownedBy($otherOwner)->create();

        $this->actingAsApi($otherOwner)
            ->deleteJson('/api/business/tokens/'.$token->getKey())
            ->assertForbidden();

        $this->assertDatabaseHas('sale_tokens', ['id' => $token->getKey()]);
    }
}
