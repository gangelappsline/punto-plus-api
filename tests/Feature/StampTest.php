<?php

namespace Tests\Feature;

use App\Enums\CardStatus;
use App\Models\Business;
use App\Models\CustomerCard;
use App\Models\LoyaltyCard;
use App\Models\Stamp;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesOAuthClients;
use Tests\TestCase;

class StampTest extends TestCase
{
    use CreatesOAuthClients, RefreshDatabase;

    private User $owner;

    private Business $business;

    private LoyaltyCard $card;

    private User $cliente;

    private CustomerCard $customerCard;

    protected function setUp(): void
    {
        parent::setUp();

        $this->createOAuthClients();

        // La suite registra varios sellos seguidos: sin espera anti-fraude.
        config(['punto_plus.cards.stamp_throttle_seconds' => 0]);

        $this->owner = User::factory()->negocio()->create();
        $this->business = Business::factory()->ownedBy($this->owner)->create();
        $this->card = LoyaltyCard::factory()->forBusiness($this->business)->create([
            'required_stamps' => 3,
            'join_code' => 'SELLOS01',
        ]);

        $this->cliente = User::factory()->cliente()->create();
        $this->customerCard = CustomerCard::factory()->forUser($this->cliente)->forCard($this->card)->create();
    }

    public function test_el_negocio_registra_un_sello_escaneando_el_qr_del_cliente(): void
    {
        $response = $this->actingAsApi($this->owner)->postJson('/api/business/stamps/scan', [
            'code' => $this->customerCard->code,
            'purchase_amount' => 4.5,
            'notes' => 'Café con leche',
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.customer_card_id', $this->customerCard->getKey())
            ->assertJsonPath('data.source', 'scan')
            ->assertJsonPath('customer_card.stamps_count', 1)
            ->assertJsonPath('customer_card.required_stamps', 3);

        $this->assertDatabaseCount('stamps', 1);
        $this->assertSame(1, $this->customerCard->fresh()->stamps_count);
    }

    public function test_al_completar_los_sellos_la_tarjeta_queda_completada(): void
    {
        foreach (range(1, 3) as $ignored) {
            $this->actingAsApi($this->owner)
                ->postJson('/api/business/stamps/scan', ['code' => $this->customerCard->code])
                ->assertCreated();
        }

        $card = $this->customerCard->fresh();

        $this->assertSame(3, $card->stamps_count);
        $this->assertSame(CardStatus::Completed, $card->status);
        $this->assertSame(1, $card->rewards_earned);
        $this->assertNotNull($card->completed_at);
        $this->assertTrue($card->isCompleted());
    }

    public function test_no_se_puede_sellar_una_tarjeta_de_otro_negocio(): void
    {
        $otroNegocio = Business::factory()->ownedBy(User::factory()->negocio()->create())->create();

        $this->actingAsApi($otroNegocio->owner)
            ->postJson('/api/business/stamps/scan', ['code' => $this->customerCard->code])
            ->assertStatus(422)
            ->assertJsonValidationErrors('code');

        $this->assertDatabaseCount('stamps', 0);
    }

    public function test_codigo_inexistente_devuelve_422(): void
    {
        $this->actingAsApi($this->owner)
            ->postJson('/api/business/stamps/scan', ['code' => 'NOEXISTEXX'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('code');
    }

    public function test_un_cliente_no_puede_registrar_sellos(): void
    {
        $this->actingAsApi($this->cliente)
            ->postJson('/api/business/stamps/scan', ['code' => $this->customerCard->code])
            ->assertStatus(403);
    }

    public function test_listados_de_sellos_del_negocio(): void
    {
        Stamp::factory()->forCard($this->customerCard)->count(2)->create([
            'stamped_at' => now()->subMinutes(5),
        ]);

        $this->actingAsApi($this->owner)
            ->getJson('/api/business/stamps/recent')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonStructure(['data' => [['id', 'stamped_at', 'customer_card' => ['code', 'user']]]]);

        $this->actingAsApi($this->owner)
            ->getJson('/api/business/stamps?loyalty_card_id='.$this->card->getKey())
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.total', 2);
    }

    public function test_el_negocio_puede_anular_un_sello(): void
    {
        $stamp = Stamp::factory()->forCard($this->customerCard)->create();
        $this->customerCard->forceFill(['stamps_count' => 1, 'total_stamps_earned' => 1])->save();

        $this->actingAsApi($this->owner)
            ->deleteJson('/api/business/stamps/'.$stamp->getKey())
            ->assertOk()
            ->assertJsonPath('customer_card.stamps_count', 0);

        $this->assertDatabaseCount('stamps', 0);
        $this->assertSame(0, $this->customerCard->fresh()->stamps_count);
    }

    public function test_el_historial_de_sellos_del_cliente_es_paginado(): void
    {
        Stamp::factory()->forCard($this->customerCard)->count(3)->create();

        $this->actingAsApi($this->cliente)
            ->getJson('/api/customer/cards/'.$this->customerCard->getKey().'/stamps?per_page=2')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.total', 3);

        $this->actingAsApi($this->cliente)
            ->getJson('/api/customer/cards/'.$this->customerCard->getKey().'/qr')
            ->assertOk()
            ->assertJsonPath('payload.type', 'customer_card.identify')
            ->assertJsonPath('payload.code', $this->customerCard->code);
    }
}
