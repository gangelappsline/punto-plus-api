<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\CustomerCard;
use App\Models\LoyaltyCard;
use App\Models\Reward;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\CreatesOAuthClients;
use Tests\TestCase;

class LoyaltyCardTest extends TestCase
{
    use CreatesOAuthClients, RefreshDatabase;

    private User $owner;

    private Business $business;

    protected function setUp(): void
    {
        parent::setUp();

        $this->createOAuthClients();

        $this->owner = User::factory()->negocio()->create(['name' => 'Lucía', 'email' => 'lucia@example.com']);
        $this->business = Business::factory()->ownedBy($this->owner)->create([
            'name' => 'Café Aurora',
            'slug' => 'cafe-aurora',
        ]);
    }

    public function test_el_negocio_crea_una_tarjeta_con_recompensas_iniciales(): void
    {
        $response = $this->actingAsApi($this->owner)->postJson('/api/business/cards', [
            'name' => 'Club del café',
            'description' => '6 sellos = un café gratis',
            'required_stamps' => 6,
            'reward_description' => 'Café de especialidad gratis',
            'primary_color' => '#F59E0B',
            'rewards' => [
                ['name' => 'Croissant gratis', 'required_stamps' => 3],
                ['name' => 'Café gratis', 'required_stamps' => 6],
            ],
            'inherit_business_branding' => true,
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.name', 'Club del café')
            ->assertJsonPath('data.required_stamps', 6)
            ->assertJsonPath('data.business_id', $this->business->getKey())
            ->assertJsonCount(2, 'data.rewards');

        $this->assertMatchesRegularExpression('/^[A-Z0-9]{8}$/', (string) $response->json('data.join_code'));

        $this->assertDatabaseHas('loyalty_cards', [
            'business_id' => $this->business->getKey(),
            'name' => 'Club del café',
            'required_stamps' => 6,
        ]);

        $this->assertDatabaseCount('rewards', 2);
    }

    public function test_validacion_de_sellos_requeridos(): void
    {
        $this->actingAsApi($this->owner)
            ->postJson('/api/business/cards', ['name' => 'Tarjeta', 'required_stamps' => 0])
            ->assertStatus(422)
            ->assertJsonValidationErrors('required_stamps');
    }

    public function test_un_cliente_no_puede_gestionar_tarjetas_de_negocio(): void
    {
        $cliente = User::factory()->cliente()->create();

        $this->actingAsApi($cliente)->getJson('/api/business/cards')->assertStatus(403);
        $this->actingAsApi($cliente)->postJson('/api/business/cards', ['name' => 'Intruso'])->assertStatus(403);
    }

    public function test_listado_y_actualizacion_de_tarjetas_del_negocio(): void
    {
        $card = LoyaltyCard::factory()->forBusiness($this->business)->create(['name' => 'Tarjeta básica']);

        $this->actingAsApi($this->owner)
            ->getJson('/api/business/cards')
            ->assertOk()
            ->assertJsonPath('data.0.name', 'Tarjeta básica');

        $this->actingAsApi($this->owner)
            ->putJson('/api/business/cards/'.$card->getKey(), [
                'name' => 'Tarjeta premium',
                'required_stamps' => 8,
                'is_active' => false,
            ])
            ->assertOk()
            ->assertJsonPath('data.name', 'Tarjeta premium')
            ->assertJsonPath('data.required_stamps', 8)
            ->assertJsonPath('data.is_active', false);
    }

    public function test_otro_negocio_no_puede_editar_la_tarjeta(): void
    {
        $card = LoyaltyCard::factory()->forBusiness($this->business)->create();
        $intruso = User::factory()->negocio()->create();
        Business::factory()->ownedBy($intruso)->create();

        $this->actingAsApi($intruso)
            ->putJson('/api/business/cards/'.$card->getKey(), ['name' => 'Robada'])
            ->assertStatus(403);
    }

    public function test_subida_de_assets_de_la_tarjeta(): void
    {
        Storage::fake('public');

        $card = LoyaltyCard::factory()->forBusiness($this->business)->create();

        $file = UploadedFile::fake()->createWithContent(
            'logo.png',
            (string) file_get_contents(base_path('tests/Fixtures/images/logo.png')),
        );

        $response = $this->actingAsApi($this->owner)
            ->post('/api/business/cards/'.$card->getKey().'/upload-assets', [
                'type' => 'logo',
                'file' => $file,
            ]);

        $response->assertOk()->assertJsonPath('data.logo_url', fn ($url) => is_string($url) && str_contains($url, 'loyalty-cards/'));

        $path = $card->fresh()->logo_path;

        $this->assertNotNull($path);
        Storage::disk('public')->assertExists($path);
    }

    public function test_un_cliente_se_une_por_qr_y_la_operacion_es_idempotente(): void
    {
        $card = LoyaltyCard::factory()->forBusiness($this->business)->create([
            'join_code' => 'CAFE2026',
            'required_stamps' => 6,
        ]);

        $cliente = User::factory()->cliente()->create();

        $first = $this->actingAsApi($cliente)->postJson('/api/customer/cards/join', ['code' => 'cafe2026']);
        $first->assertCreated()->assertJsonPath('already_joined', false)->assertJsonPath('data.stamps_count', 0);

        $second = $this->actingAsApi($cliente)->postJson('/api/customer/cards/join', [
            'code' => json_encode(['v' => 1, 'type' => 'loyalty_card.join', 'join_code' => 'CAFE2026']),
        ]);

        $second->assertOk()->assertJsonPath('already_joined', true);

        $this->assertDatabaseCount('customer_cards', 1);
        $this->assertSame($this->business->getKey(), CustomerCard::first()->business_id);
    }

    public function test_unirse_con_codigo_invalido_devuelve_422(): void
    {
        $cliente = User::factory()->cliente()->create();

        $this->actingAsApi($cliente)
            ->postJson('/api/customer/cards/join', ['code' => 'NOEXISTE'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('code');
    }

    public function test_el_cliente_ve_sus_tarjetas_y_su_historial_de_sellos(): void
    {
        $card = LoyaltyCard::factory()->forBusiness($this->business)->create(['required_stamps' => 6, 'join_code' => 'CAFE2026']);
        $cliente = User::factory()->cliente()->create();
        $card->rewards()->create([
            'business_id' => $this->business->getKey(),
            'name' => 'Café gratis',
            'required_stamps' => 6,
        ]);

        $customerCard = CustomerCard::factory()->forUser($cliente)->forCard($card, 2)->create();

        $this->actingAsApi($cliente)
            ->getJson('/api/customer/cards')
            ->assertOk()
            ->assertJsonPath('data.0.stamps_count', 2)
            ->assertJsonPath('data.0.required_stamps', 6)
            ->assertJsonPath('data.0.progress_percentage', 33.33)
            ->assertJsonPath('data.0.status', 'active');

        $this->actingAsApi($cliente)
            ->getJson('/api/customer/cards/'.$customerCard->getKey().'/stamps')
            ->assertOk();

        $this->actingAsApi($cliente)
            ->getJson('/api/customer/cards/'.$customerCard->getKey())
            ->assertOk()
            ->assertJsonPath('data.loyalty_card.name', $card->name);
    }

    public function test_el_cliente_no_puede_ver_la_tarjeta_de_otro_cliente(): void
    {
        $card = LoyaltyCard::factory()->forBusiness($this->business)->create();
        $customerCard = CustomerCard::factory()->forCard($card)->create();
        $intruso = User::factory()->cliente()->create();

        $this->actingAsApi($intruso)
            ->getJson('/api/customer/cards/'.$customerCard->getKey())
            ->assertStatus(403);
    }

    public function test_salir_y_volver_a_unirse_restaura_la_tarjeta(): void
    {
        $card = LoyaltyCard::factory()->forBusiness($this->business)->create(['join_code' => 'CAFE2026']);
        $cliente = User::factory()->cliente()->create();
        $customerCard = CustomerCard::factory()->forUser($cliente)->forCard($card, 3)->create();

        $this->actingAsApi($cliente)
            ->deleteJson('/api/customer/cards/'.$customerCard->getKey())
            ->assertOk();

        $this->assertSoftDeleted('customer_cards', ['id' => $customerCard->getKey()]);

        $this->actingAsApi($cliente)
            ->postJson('/api/customer/cards/join', ['code' => 'CAFE2026'])
            ->assertOk()
            ->assertJsonPath('data.stamps_count', 3);

        $this->assertNull($customerCard->fresh()->deleted_at);
    }

    public function test_el_negocio_puede_regenerar_el_codigo_de_alta_y_ver_el_qr(): void
    {
        $card = LoyaltyCard::factory()->forBusiness($this->business)->create(['join_code' => 'VIEJO123']);

        $this->actingAsApi($this->owner)
            ->postJson('/api/business/cards/'.$card->getKey().'/regenerate-join-code')
            ->assertOk()
            ->assertJsonStructure(['join_code', 'qr' => ['payload', 'json', 'svg']]);

        $this->assertNotSame('VIEJO123', $card->fresh()->join_code);

        $this->actingAsApi($this->owner)
            ->getJson('/api/business/cards/'.$card->getKey().'/qr')
            ->assertOk()
            ->assertJsonPath('payload.type', 'loyalty_card.join');
    }

    public function test_el_negocio_ve_a_sus_clientes(): void
    {
        $card = LoyaltyCard::factory()->forBusiness($this->business)->create();
        Reward::factory()->forCard($card)->create();
        CustomerCard::factory()->forCard($card, 5)->create();

        $this->actingAsApi($this->owner)
            ->getJson('/api/business/customers')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.stamps_count', 5)
            ->assertJsonStructure(['data' => [['id', 'code', 'user' => ['id', 'name']]]]);
    }
}
