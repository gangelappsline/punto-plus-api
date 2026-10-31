<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Concerns\CreatesOAuthClients;
use Tests\TestCase;

class BusinessOnboardingTest extends TestCase
{
    use CreatesOAuthClients, RefreshDatabase;

    private User $owner;

    private Business $business;

    private string $imageDataUri;

    protected function setUp(): void
    {
        parent::setUp();

        $this->createOAuthClients();
        Storage::fake('public');
        $this->owner = User::factory()->negocio()->create();
        $this->business = Business::factory()->ownedBy($this->owner)->create();
        $this->imageDataUri = 'data:image/png;base64,'.base64_encode(
            (string) file_get_contents(base_path('tests/Fixtures/images/logo.png')),
        );
    }

    public function test_negocio_puede_guardar_cada_paso_del_onboarding(): void
    {
        $category = Category::create(['name' => 'Bebidas']);
        $productId = (string) Str::uuid();

        $this->actingAsApi($this->owner, [])->putJson('/api/business/onboarding', [
            'step' => 'information',
            'information' => [
                'name' => 'Café Aurora',
                'description' => 'Café de especialidad',
                'welcome_message' => '¡Bienvenido!',
                'logo_url' => $this->imageDataUri,
                'background_url' => $this->imageDataUri,
                'phone' => '+525555555555',
                'phone_numbers' => ['+525555555555', '+525555555556'],
                'address' => 'Roma Norte, CDMX',
            ],
        ])->assertOk()
            ->assertJsonPath('data.step', 'information')
            ->assertJsonPath('data.business.name', 'Café Aurora')
            ->assertJsonPath('data.business.card_settings.welcome_message', '¡Bienvenido!')
            ->assertJsonPath('data.business.welcome_message', '¡Bienvenido!')
            ->assertJsonPath('data.business.phone_numbers.1', '+525555555556');

        Storage::disk('public')->assertExists($this->business->fresh()->logo_path);
        Storage::disk('public')->assertExists($this->business->fresh()->background_path);

        $this->actingAsApi($this->owner, [])->putJson('/api/business/onboarding', [
            'step' => 'categories',
            'categories' => ['category_ids' => [$category->id]],
        ])->assertOk()
            ->assertJsonPath('data.step', 'categories')
            ->assertJsonPath('data.business.categories.0.name', 'Bebidas');

        $this->actingAsApi($this->owner, [])->putJson('/api/business/onboarding', [
            'step' => 'products',
            'products' => ['products' => [[
                'id' => $productId,
                'name' => 'Latte',
                'description' => 'Doble espresso con leche',
                'price' => 65.50,
                'category_id' => $category->id,
                'image_url' => $this->imageDataUri,
                'position' => 2,
                'available' => true,
            ]]],
        ])->assertOk()->assertJsonPath('data.step', 'products');

        $product = Product::with('images')->findOrFail($productId);
        $this->assertSame($this->business->getKey(), $product->business_id);
        $this->assertSame($category->id, $product->category_id);
        $this->assertNotNull($product->menu_category_id);
        $this->assertSame(2, $product->sort_order);
        $this->assertTrue($product->is_available);
        $this->assertCount(1, $product->images);
        Storage::disk('public')->assertExists($product->images->first()->path);
        $this->getJson('/api/public/business/'.$this->business->slug.'/menu')
            ->assertOk()
            ->assertJsonPath('data.0.source_category_id', $category->id)
            ->assertJsonPath('data.0.products.0.id', $productId);

        $this->actingAsApi($this->owner, [])->putJson('/api/business/onboarding', [
            'step' => 'design',
            'design' => [
                'primary_color' => '#123456',
                'secondary_color' => '#ABCDEF',
                'font_family' => 'Inter',
                'menu_style' => 'grid',
            ],
        ])->assertOk()
            ->assertJsonPath('data.business.menu_design.primary_color', '#123456')
            ->assertJsonPath('data.business.menu_design.menu_style', 'grid');
    }

    public function test_selector_de_paso_es_obligatorio_y_productos_deben_usar_categorias_del_negocio(): void
    {
        $category = Category::create(['name' => 'Postres']);
        $payload = [
            'step' => 'products',
            'products' => ['products' => [[
                'id' => (string) Str::uuid(),
                'name' => 'Pastel',
                'category_id' => $category->id,
                'image_url' => $this->imageDataUri,
            ]]],
        ];

        $this->actingAsApi($this->owner, [])->putJson('/api/business/onboarding', $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('products.products.0.category_id');

        $this->actingAsApi($this->owner, [])->putJson('/api/business/onboarding', [
            'information' => ['name' => 'Sin selector'],
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('step');
    }
}
