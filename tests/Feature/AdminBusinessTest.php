<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Business;
use App\Models\Category;
use App\Models\CustomerCard;
use App\Models\LoyaltyCard;
use App\Models\Stamp;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\CreatesOAuthClients;
use Tests\TestCase;

class AdminBusinessTest extends TestCase
{
    use CreatesOAuthClients, RefreshDatabase;

    private User $admin;

    private string $imageDataUri;

    protected function setUp(): void
    {
        parent::setUp();

        $this->createOAuthClients();
        config(['punto_plus.assets.disk' => 'public']);
        Storage::fake('public');

        $this->admin = User::factory()->admin()->create();
        $this->imageDataUri = 'data:image/png;base64,'.base64_encode(
            (string) file_get_contents(base_path('tests/Fixtures/images/logo.png')),
        );
    }

    // -----------------------------------------------------------------
    // Listado + estadísticas
    // -----------------------------------------------------------------

    public function test_el_listado_devuelve_estadisticas_globales_y_negocios_paginados(): void
    {
        $active = Business::factory()->create(['name' => 'Café Activo']);
        Business::factory()->inactive()->create(['name' => 'Panadería Inactiva']);

        $card = LoyaltyCard::factory()->forBusiness($active)->create(['required_stamps' => 5]);
        $customerCard = CustomerCard::factory()->forCard($card, 2)->create();
        Stamp::factory()->forCard($customerCard)->count(3)->create();

        $response = $this->actingAsApi($this->admin)->getJson('/api/admin/business');

        $response->assertOk()
            ->assertJsonStructure([
                'stats' => [
                    'businesses', 'owners', 'loyalty_cards', 'customers', 'stamps',
                    'redemptions', 'promotions', 'rewards', 'products',
                    'averages', 'growth', 'top_businesses', 'generated_at',
                ],
                'businesses' => ['data', 'links', 'meta'],
            ])
            ->assertJsonPath('stats.businesses.total', 2)
            ->assertJsonPath('stats.businesses.active', 1)
            ->assertJsonPath('stats.businesses.inactive', 1)
            ->assertJsonPath('stats.businesses.trashed', 0)
            ->assertJsonPath('stats.loyalty_cards.total', 1)
            ->assertJsonPath('stats.customers.total', 1)
            ->assertJsonPath('stats.stamps.total', 3)
            ->assertJsonPath('stats.products.total', 0)
            ->assertJsonCount(6, 'stats.growth')
            ->assertJsonPath('businesses.meta.total', 2)
            ->assertJsonPath('stats.generated_at', fn ($value) => is_string($value));

        $this->actingAsApi($this->admin)
            ->getJson('/api/admin/business?search='.urlencode('Café Activo'))
            ->assertOk()
            ->assertJsonPath('businesses.meta.total', 1)
            ->assertJsonPath('businesses.data.0.id', $active->id)
            ->assertJsonPath('businesses.data.0.customers_count', 1)
            ->assertJsonPath('businesses.data.0.loyalty_cards_count', 1)
            ->assertJsonPath('businesses.data.0.stamps_count', 3)
            ->assertJsonPath('businesses.data.0.is_deleted', false)
            ->assertJsonPath('stats.top_businesses.0.id', $active->id);
    }

    public function test_el_listado_filtra_por_estado_busqueda_ciudad_categoria_y_eliminados(): void
    {
        $active = Business::factory()->create(['name' => 'Café Aurora', 'city' => 'CDMX', 'category' => 'cafetería']);
        $inactive = Business::factory()->inactive()->create(['name' => 'Bar Arena', 'city' => 'Monterrey']);
        $deleted = Business::factory()->create(['name' => 'Tienda Fantasma', 'city' => 'CDMX']);
        $deleted->delete();

        $category = Category::create(['name' => 'Cafeterías']);
        $active->categories()->create(['category_id' => $category->id, 'sort_order' => 0]);

        $this->actingAsApi($this->admin)->getJson('/api/admin/business?is_active=0')
            ->assertOk()
            ->assertJsonPath('businesses.meta.total', 1)
            ->assertJsonPath('businesses.data.0.id', $inactive->id);

        $this->actingAsApi($this->admin)->getJson('/api/admin/business?search='.urlencode('Café Aurora'))
            ->assertOk()
            ->assertJsonPath('businesses.meta.total', 1)
            ->assertJsonPath('businesses.data.0.id', $active->id);

        $this->actingAsApi($this->admin)->getJson('/api/admin/business?city=CDMX')
            ->assertOk()
            ->assertJsonPath('businesses.meta.total', 1)
            ->assertJsonPath('businesses.data.0.id', $active->id);

        $this->actingAsApi($this->admin)->getJson('/api/admin/business?category_id='.$category->id)
            ->assertOk()
            ->assertJsonPath('businesses.meta.total', 1)
            ->assertJsonPath('businesses.data.0.id', $active->id);

        $this->actingAsApi($this->admin)->getJson('/api/admin/business?trashed=only')
            ->assertOk()
            ->assertJsonPath('businesses.meta.total', 1)
            ->assertJsonPath('businesses.data.0.id', $deleted->id)
            ->assertJsonPath('businesses.data.0.is_deleted', true);

        $this->actingAsApi($this->admin)->getJson('/api/admin/business?trashed=with')
            ->assertOk()
            ->assertJsonPath('businesses.meta.total', 3);
    }

    public function test_el_listado_ordena_por_numero_de_clientes(): void
    {
        $small = Business::factory()->create(['name' => 'Pequeño']);
        $big = Business::factory()->create(['name' => 'Grande']);

        $smallCard = LoyaltyCard::factory()->forBusiness($small)->create();
        CustomerCard::factory()->forCard($smallCard)->create();

        $bigCard = LoyaltyCard::factory()->forBusiness($big)->create();
        CustomerCard::factory()->forCard($bigCard)->count(3)->create();

        $this->actingAsApi($this->admin)
            ->getJson('/api/admin/business?sort=customers_count&direction=desc')
            ->assertOk()
            ->assertJsonPath('businesses.data.0.id', $big->id)
            ->assertJsonPath('businesses.data.0.customers_count', 3)
            ->assertJsonPath('businesses.data.1.id', $small->id);
    }

    // -----------------------------------------------------------------
    // Alta
    // -----------------------------------------------------------------

    public function test_admin_crea_un_negocio_con_un_propietario_nuevo(): void
    {
        $category = Category::create(['name' => 'Cafeterías']);

        $response = $this->actingAsApi($this->admin)->postJson('/api/admin/business', [
            'owner' => [
                'name' => 'Lucía Gómez',
                'email' => 'lucia@puntoplus.test',
                'password' => 'password123',
                'phone' => '+525555555551',
            ],
            'name' => 'Café Aurora',
            'description' => 'Cafetería de especialidad',
            'category' => 'cafetería',
            'phone' => '+525555555555',
            'phone_numbers' => ['+525555555555', '+525555555556'],
            'email' => 'hola@cafeaurora.test',
            'city' => 'CDMX',
            'country' => 'mx',
            'is_active' => true,
            'logo_url' => $this->imageDataUri,
            'category_ids' => [$category->id],
            'card_settings' => [
                'primary_color' => '#F59E0B',
                'required_stamps' => 6,
                'welcome_message' => '¡Bienvenido!',
            ],
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.name', 'Café Aurora')
            ->assertJsonPath('data.slug', 'cafe-aurora')
            ->assertJsonPath('data.country', 'MX')
            ->assertJsonPath('data.is_active', true)
            ->assertJsonPath('data.owner.email', 'lucia@puntoplus.test')
            ->assertJsonPath('data.categories.0.category_id', $category->id)
            ->assertJsonPath('data.card_settings.required_stamps', 6)
            ->assertJsonPath('data.phone_numbers.1', '+525555555556')
            ->assertJsonPath('data.loyalty_cards_count', 0)
            ->assertJsonPath('data.logo_url', fn ($url) => is_string($url) && str_contains($url, '/storage/businesses/'));

        $business = Business::query()->where('slug', 'cafe-aurora')->firstOrFail();
        $owner = User::query()->where('email', 'lucia@puntoplus.test')->firstOrFail();

        $this->assertSame(UserRole::Negocio, $owner->role);
        $this->assertSame($owner->getKey(), $business->user_id);
        $this->assertDatabaseHas('business_categories', [
            'business_id' => $business->getKey(),
            'category_id' => $category->id,
            'sort_order' => 0,
        ]);
        Storage::disk('public')->assertExists((string) $business->logo_path);
    }

    public function test_admin_crea_un_negocio_para_un_propietario_existente(): void
    {
        $owner = User::factory()->negocio()->create();

        $this->actingAsApi($this->admin)->postJson('/api/admin/business', [
            'user_id' => $owner->getKey(),
            'name' => 'Bar Arena',
        ])->assertCreated()
            ->assertJsonPath('data.name', 'Bar Arena')
            ->assertJsonPath('data.owner.id', $owner->getKey());

        $this->assertSame($owner->getKey(), Business::query()->firstOrFail()->user_id);
    }

    public function test_el_propietario_debe_tener_rol_de_negocio_y_no_tener_otro_negocio(): void
    {
        $client = User::factory()->cliente()->create();
        $owner = User::factory()->negocio()->create();
        Business::factory()->ownedBy($owner)->create();

        $this->actingAsApi($this->admin)->postJson('/api/admin/business', [
            'user_id' => $client->getKey(),
            'name' => 'Bar Arena',
        ])->assertUnprocessable()->assertJsonValidationErrors('user_id');

        $this->actingAsApi($this->admin)->postJson('/api/admin/business', [
            'user_id' => $owner->getKey(),
            'name' => 'Bar Arena',
        ])->assertUnprocessable()->assertJsonValidationErrors('user_id');

        $this->actingAsApi($this->admin)->postJson('/api/admin/business', [
            'name' => 'Bar Arena',
        ])->assertUnprocessable()->assertJsonValidationErrors(['user_id', 'owner']);

        $this->assertSame(0, Business::query()->where('name', 'Bar Arena')->count());
    }

    // -----------------------------------------------------------------
    // Detalle
    // -----------------------------------------------------------------

    public function test_admin_consulta_el_detalle_de_un_negocio(): void
    {
        $business = Business::factory()->create(['name' => 'Café Aurora']);
        $card = LoyaltyCard::factory()->forBusiness($business)->create(['required_stamps' => 5]);
        $customerCard = CustomerCard::factory()->forCard($card, 2)->create();
        Stamp::factory()->forCard($customerCard)->count(2)->create();

        $this->actingAsApi($this->admin)->getJson('/api/admin/business/'.$business->getKey())
            ->assertOk()
            ->assertJsonPath('data.id', $business->getKey())
            ->assertJsonPath('data.customers_count', 1)
            ->assertJsonPath('data.loyalty_cards_count', 1)
            ->assertJsonPath('data.loyalty_cards.0.id', $card->getKey())
            ->assertJsonPath('data.stats.customers_total', 1)
            ->assertJsonPath('data.stats.stamps_total', 2)
            ->assertJsonPath('data.stats.loyalty_cards_total', 1)
            ->assertJsonPath('data.stats.avg_stamps_per_customer', fn ($value) => (float) $value === 2.0);
    }

    // -----------------------------------------------------------------
    // Edición
    // -----------------------------------------------------------------

    public function test_admin_actualiza_un_negocio(): void
    {
        $business = Business::factory()->create([
            'name' => 'Café Aurora',
            'slug' => 'cafe-aurora',
            'card_settings' => ['primary_color' => '#F59E0B', 'stamp_icon' => 'coffee'],
        ]);
        $category = Category::create(['name' => 'Cafeterías']);
        $newOwner = User::factory()->negocio()->create();

        $this->actingAsApi($this->admin)->putJson('/api/admin/business/'.$business->getKey(), [
            'name' => 'Café Aurora Centro',
            'phone_numbers' => '+525555555555, +525555555556',
            'is_active' => false,
            'user_id' => $newOwner->getKey(),
            'city' => 'CDMX',
            'category_ids' => [$category->id],
            'logo_url' => $this->imageDataUri,
            'card_settings' => ['welcome_message' => 'Hola de nuevo'],
        ])->assertOk()
            ->assertJsonPath('data.name', 'Café Aurora Centro')
            ->assertJsonPath('data.slug', 'cafe-aurora-centro')
            ->assertJsonPath('data.is_active', false)
            ->assertJsonPath('data.city', 'CDMX')
            ->assertJsonPath('data.owner.id', $newOwner->getKey())
            ->assertJsonPath('data.phone_numbers.1', '+525555555556')
            ->assertJsonPath('data.card_settings.welcome_message', 'Hola de nuevo')
            ->assertJsonPath('data.card_settings.stamp_icon', 'coffee')
            ->assertJsonPath('data.stats.customers_total', 0);

        $business->refresh();
        $logoPath = (string) $business->logo_path;

        $this->assertSame($newOwner->getKey(), $business->user_id);
        $this->assertSame('cafe-aurora-centro', $business->slug);
        $this->assertDatabaseHas('business_categories', [
            'business_id' => $business->getKey(),
            'category_id' => $category->id,
        ]);
        Storage::disk('public')->assertExists($logoPath);

        // El slug también se puede fijar explícitamente y la imagen se elimina con null.
        $this->actingAsApi($this->admin)->patchJson('/api/admin/business/'.$business->getKey(), [
            'slug' => 'aurora-centro',
            'logo_url' => null,
        ])->assertOk()
            ->assertJsonPath('data.slug', 'aurora-centro')
            ->assertJsonPath('data.logo_url', null);

        Storage::disk('public')->assertMissing($logoPath);
        $this->assertNull($business->fresh()->logo_path);
    }

    public function test_no_se_puede_reasignar_el_negocio_a_un_propietario_invalido(): void
    {
        $business = Business::factory()->create();
        $client = User::factory()->cliente()->create();
        $otherOwner = User::factory()->negocio()->create();
        Business::factory()->ownedBy($otherOwner)->create();

        $this->actingAsApi($this->admin)->putJson('/api/admin/business/'.$business->getKey(), [
            'user_id' => $client->getKey(),
        ])->assertUnprocessable()->assertJsonValidationErrors('user_id');

        $this->actingAsApi($this->admin)->putJson('/api/admin/business/'.$business->getKey(), [
            'user_id' => $otherOwner->getKey(),
        ])->assertUnprocessable()->assertJsonValidationErrors('user_id');

        $this->assertSame($business->user_id, $business->fresh()->user_id);
    }

    // -----------------------------------------------------------------
    // Baja y restauración
    // -----------------------------------------------------------------

    public function test_admin_elimina_y_restaura_un_negocio(): void
    {
        $business = Business::factory()->create();

        $this->actingAsApi($this->admin)->deleteJson('/api/admin/business/'.$business->getKey())
            ->assertOk()
            ->assertJsonPath('id', $business->getKey())
            ->assertJsonPath('deleted_at', fn ($value) => is_string($value));

        $this->assertSoftDeleted('businesses', ['id' => $business->getKey()]);

        $this->actingAsApi($this->admin)->getJson('/api/admin/business/'.$business->getKey())
            ->assertNotFound();

        $this->actingAsApi($this->admin)->postJson('/api/admin/business/'.$business->getKey().'/restore')
            ->assertOk()
            ->assertJsonPath('data.id', $business->getKey())
            ->assertJsonPath('message', 'Negocio restaurado.');

        $this->assertDatabaseHas('businesses', ['id' => $business->getKey(), 'deleted_at' => null]);
    }

    public function test_solo_un_administrador_puede_gestionar_negocios(): void
    {
        $owner = User::factory()->negocio()->create();
        $business = Business::factory()->ownedBy($owner)->create();

        $this->actingAsApi($owner)->getJson('/api/admin/business')->assertForbidden();
        $this->actingAsApi($owner)->getJson('/api/admin/business/'.$business->getKey())->assertForbidden();
        $this->actingAsApi($owner)
            ->putJson('/api/admin/business/'.$business->getKey(), ['name' => 'Otro nombre'])
            ->assertForbidden();
        $this->actingAsApi($owner)->deleteJson('/api/admin/business/'.$business->getKey())->assertForbidden();
    }
}
