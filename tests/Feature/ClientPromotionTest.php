<?php

namespace Tests\Feature;

use App\Enums\DiscountType;
use App\Models\Business;
use App\Models\CustomerCard;
use App\Models\LoyaltyCard;
use App\Models\Promotion;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesOAuthClients;
use Tests\TestCase;

/**
 * `GET /api/client/promotions`: promociones de los negocios con los que el
 * cliente tiene **o ha tenido** tarjeta.
 */
class ClientPromotionTest extends TestCase
{
    use CreatesOAuthClients, RefreshDatabase;

    private User $cliente;

    private Business $activeBusiness;

    private Business $formerBusiness;

    private Business $unrelatedBusiness;

    protected function setUp(): void
    {
        parent::setUp();

        $this->createOAuthClients();

        $this->cliente = User::factory()->cliente()->create();

        $this->activeBusiness = Business::factory()->create(['name' => 'Café Vigente']);
        $this->formerBusiness = Business::factory()->create(['name' => 'Bar Antiguo']);
        $this->unrelatedBusiness = Business::factory()->create(['name' => 'Tienda Ajena']);

        // Tarjeta vigente en un negocio y tarjeta dada de baja en otro.
        $this->subscribe($this->activeBusiness);
        $this->subscribe($this->formerBusiness, leave: true);
    }

    public function test_devuelve_promociones_de_los_negocios_donde_tengo_o_he_tenido_tarjeta(): void
    {
        Promotion::factory()->forBusiness($this->activeBusiness)->create(['title' => '2x1 vigente']);
        Promotion::factory()->forBusiness($this->formerBusiness)->create(['title' => 'Descuento antiguo']);
        Promotion::factory()->forBusiness($this->unrelatedBusiness)->create(['title' => 'No debería salir']);

        $response = $this->actingAsApi($this->cliente)->getJson('/api/client/promotions');

        $response->assertOk()->assertJsonCount(2, 'data');

        $this->assertEqualsCanonicalizing(
            ['2x1 vigente', 'Descuento antiguo'],
            array_column($response->json('data'), 'title'),
        );
    }

    public function test_no_devuelve_promociones_caducadas_inactivas_ni_de_negocios_inactivos(): void
    {
        Promotion::factory()->forBusiness($this->activeBusiness)->create(['title' => 'Vigente']);
        Promotion::factory()->forBusiness($this->activeBusiness)->expired()->create(['title' => 'Caducada']);
        Promotion::factory()->forBusiness($this->activeBusiness)->create(['title' => 'Desactivada', 'is_active' => false]);

        $closedBusiness = Business::factory()->inactive()->create(['name' => 'Local Cerrado']);
        $this->subscribe($closedBusiness);
        Promotion::factory()->forBusiness($closedBusiness)->create(['title' => 'De local cerrado']);

        $response = $this->actingAsApi($this->cliente)->getJson('/api/client/promotions');

        $response->assertOk()->assertJsonCount(1, 'data');
        $this->assertSame('Vigente', $response->json('data.0.title'));
    }

    public function test_incluye_el_negocio_que_publica_cada_promocion(): void
    {
        Promotion::factory()->forBusiness($this->activeBusiness)->create(['title' => 'Con negocio']);

        $response = $this->actingAsApi($this->cliente)->getJson('/api/client/promotions');

        $response->assertOk()
            ->assertJsonPath('data.0.business.id', $this->activeBusiness->getKey())
            ->assertJsonPath('data.0.business.name', 'Café Vigente');
    }

    public function test_permite_filtrar_por_negocio_busqueda_y_tipo_de_descuento(): void
    {
        Promotion::factory()->forBusiness($this->activeBusiness)->create([
            'title' => 'Café gratis',
            'description' => 'Una bebida caliente de cortesía.',
            'discount_type' => DiscountType::Percentage,
        ]);
        Promotion::factory()->forBusiness($this->formerBusiness)->create([
            'title' => 'Postre gratis',
            'description' => 'Un dulce de cortesía.',
            'discount_type' => DiscountType::FixedAmount,
        ]);

        $byBusiness = $this->actingAsApi($this->cliente)
            ->getJson('/api/client/promotions?business_id='.$this->formerBusiness->getKey());
        $byBusiness->assertOk()->assertJsonCount(1, 'data');
        $this->assertSame('Postre gratis', $byBusiness->json('data.0.title'));

        $bySearch = $this->actingAsApi($this->cliente)->getJson('/api/client/promotions?search=café');
        $bySearch->assertOk()->assertJsonCount(1, 'data');
        $this->assertSame('Café gratis', $bySearch->json('data.0.title'));

        $byType = $this->actingAsApi($this->cliente)
            ->getJson('/api/client/promotions?discount_type='.DiscountType::FixedAmount->value);
        $byType->assertOk()->assertJsonCount(1, 'data');
        $this->assertSame('Postre gratis', $byType->json('data.0.title'));
    }

    public function test_rechaza_un_tipo_de_descuento_desconocido(): void
    {
        $this->actingAsApi($this->cliente)
            ->getJson('/api/client/promotions?discount_type=inventado')
            ->assertStatus(422)
            ->assertJsonValidationErrors('discount_type');
    }

    public function test_un_cliente_sin_tarjetas_no_recibe_promociones(): void
    {
        Promotion::factory()->forBusiness($this->unrelatedBusiness)->create();

        $this->actingAsApi(User::factory()->cliente()->create())
            ->getJson('/api/client/promotions')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_la_ruta_historica_sigue_funcionando(): void
    {
        Promotion::factory()->forBusiness($this->formerBusiness)->create(['title' => 'Descuento antiguo']);

        $this->actingAsApi($this->cliente)
            ->getJson('/api/customer/promotions')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.title', 'Descuento antiguo');
    }

    public function test_requiere_autenticacion(): void
    {
        $this->getJson('/api/client/promotions')->assertUnauthorized();
    }

    /**
     * Da de alta (y opcionalmente de baja) una tarjeta del cliente en el negocio.
     */
    private function subscribe(Business $business, bool $leave = false): CustomerCard
    {
        $loyaltyCard = LoyaltyCard::factory()->forBusiness($business)->create();

        $customerCard = CustomerCard::factory()
            ->forCard($loyaltyCard)
            ->forUser($this->cliente)
            ->create();

        if ($leave) {
            $customerCard->delete();
        }

        return $customerCard;
    }
}
