<?php

namespace Tests\Feature;

use App\Enums\CardStatus;
use App\Models\Business;
use App\Models\CustomerCard;
use App\Models\LoyaltyCard;
use App\Models\Redemption;
use App\Models\Reward;
use App\Models\User;
use App\Services\StampService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesOAuthClients;
use Tests\TestCase;

class RedemptionTest extends TestCase
{
    use CreatesOAuthClients, RefreshDatabase;

    private User $owner;

    private Business $business;

    private LoyaltyCard $card;

    private Reward $reward;

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
        $this->card = LoyaltyCard::factory()->forBusiness($this->business)->create(['required_stamps' => 3]);
        $this->reward = Reward::factory()->forCard($this->card, 3)->create(['name' => 'Café gratis', 'stock' => 5]);

        $this->cliente = User::factory()->cliente()->create();
        $this->customerCard = CustomerCard::factory()->forUser($this->cliente)->forCard($this->card, 3)->create();
        $this->customerCard->forceFill(['status' => CardStatus::Completed])->save();
    }

    public function test_el_cliente_solicita_el_canje_y_consumen_sus_sellos(): void
    {
        $response = $this->actingAsApi($this->cliente)
            ->postJson('/api/customer/cards/'.$this->customerCard->getKey().'/redeem', [
                'reward_id' => $this->reward->getKey(),
            ]);

        $response->assertCreated()
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.stamps_used', 3)
            ->assertJsonPath('data.reward.name', 'Café gratis');

        $this->assertMatchesRegularExpression('/^PP-[A-Z0-9]{8}$/', (string) $response->json('data.code'));

        $card = $this->customerCard->fresh();

        $this->assertSame(0, $card->stamps_count);
        $this->assertSame(CardStatus::Active, $card->status);
        $this->assertSame(4, $this->reward->fresh()->stock);
    }

    public function test_no_se_puede_canjear_sin_sellos_suficientes(): void
    {
        $this->customerCard->forceFill(['stamps_count' => 1, 'status' => CardStatus::Active])->save();

        $this->actingAsApi($this->cliente)
            ->postJson('/api/customer/cards/'.$this->customerCard->getKey().'/redeem', [
                'reward_id' => $this->reward->getKey(),
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('reward_id');
    }

    public function test_no_se_puede_tener_dos_canjes_pendientes_en_la_misma_tarjeta(): void
    {
        $this->actingAsApi($this->cliente)->postJson('/api/customer/cards/'.$this->customerCard->getKey().'/redeem', [
            'reward_id' => $this->reward->getKey(),
        ])->assertCreated();

        $this->customerCard->forceFill(['stamps_count' => 3, 'status' => CardStatus::Completed])->save();

        $this->actingAsApi($this->cliente)
            ->postJson('/api/customer/cards/'.$this->customerCard->getKey().'/redeem', [
                'reward_id' => $this->reward->getKey(),
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('reward_id');
    }

    public function test_el_negocio_valida_el_canje_con_el_codigo_del_cliente(): void
    {
        $redemption = Redemption::factory()->forCard($this->customerCard, $this->reward)->create();
        $this->customerCard->forceFill(['stamps_count' => 0])->save();

        $this->actingAsApi($this->owner)
            ->postJson('/api/business/redemptions/complete', ['code' => $redemption->code])
            ->assertOk()
            ->assertJsonPath('data.status', 'completed')
            ->assertJsonPath('data.approved_by.id', $this->owner->getKey());

        $this->assertSame(1, $this->customerCard->fresh()->rewards_redeemed);

        // Un canje ya procesado no se puede volver a validar.
        $this->actingAsApi($this->owner)
            ->postJson('/api/business/redemptions/complete', ['code' => $redemption->code])
            ->assertStatus(422);
    }

    public function test_otro_negocio_no_puede_validar_el_canje(): void
    {
        $redemption = Redemption::factory()->forCard($this->customerCard, $this->reward)->create();

        $intruso = User::factory()->negocio()->create();
        Business::factory()->ownedBy($intruso)->create();

        $this->actingAsApi($intruso)
            ->postJson('/api/business/redemptions/'.$redemption->getKey().'/approve')
            ->assertStatus(403);
    }

    public function test_el_rechazo_devuelve_los_sellos_y_el_stock(): void
    {
        $redemption = Redemption::factory()->forCard($this->customerCard, $this->reward)->create();
        $this->customerCard->forceFill(['stamps_count' => 0, 'status' => CardStatus::Active])->save();
        $this->reward->forceFill(['stock' => 4, 'redemptions_count' => 1])->save();

        $this->actingAsApi($this->owner)
            ->postJson('/api/business/redemptions/'.$redemption->getKey().'/reject', [
                'reason' => 'El cliente no se presentó',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'rejected');

        $card = $this->customerCard->fresh();

        $this->assertSame(3, $card->stamps_count);
        $this->assertSame(CardStatus::Completed, $card->status);
        $this->assertSame(5, $this->reward->fresh()->stock);
        $this->assertSame(0, $this->reward->fresh()->redemptions_count);
    }

    public function test_el_cliente_puede_cancelar_su_canje_pendiente(): void
    {
        $redemption = Redemption::factory()->forCard($this->customerCard, $this->reward)->create();
        $this->customerCard->forceFill(['stamps_count' => 0, 'status' => CardStatus::Active])->save();

        $this->actingAsApi($this->cliente)
            ->postJson('/api/customer/redemptions/'.$redemption->getKey().'/cancel')
            ->assertOk()
            ->assertJsonPath('data.status', 'cancelled');

        $this->assertSame(3, $this->customerCard->fresh()->stamps_count);
    }

    public function test_el_cliente_lista_sus_canjes(): void
    {
        Redemption::factory()->forCard($this->customerCard, $this->reward)->create();

        $this->actingAsApi($this->cliente)
            ->getJson('/api/customer/redemptions?status=pending')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.reward.name', 'Café gratis');

        $this->actingAsApi($this->owner)
            ->getJson('/api/business/redemptions?status=pending')
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    public function test_flujo_completo_sello_canje_validacion(): void
    {
        $cliente = User::factory()->cliente()->create();
        $tarjeta = CustomerCard::factory()->forUser($cliente)->forCard($this->card)->create();

        $stamps = app(StampService::class);

        foreach (range(1, 3) as $ignored) {
            $stamps->register($this->business, $tarjeta, $this->owner);
        }

        $tarjeta->refresh();

        $this->assertSame(CardStatus::Completed, $tarjeta->status);

        $redemption = $this->actingAsApi($cliente)
            ->postJson('/api/customer/cards/'.$tarjeta->getKey().'/redeem', ['reward_id' => $this->reward->getKey()])
            ->assertCreated();

        $this->actingAsApi($this->owner)
            ->postJson('/api/business/redemptions/complete', ['code' => $redemption->json('data.code')])
            ->assertOk()
            ->assertJsonPath('data.status', 'completed');

        $this->assertSame(0, $tarjeta->fresh()->stamps_count);
    }
}
