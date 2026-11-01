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

        $this->actingAsApi($this->owner, [])->putJson('/api/business/card', [
            'step' => 'information',
            'data' => [
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
            ->assertJsonPath('name', 'Café Aurora')
            ->assertJsonPath('card_settings.welcome_message', '¡Bienvenido!')
            ->assertJsonPath('welcome_message', '¡Bienvenido!')
            ->assertJsonPath('phone_numbers.1', '+525555555556');

        Storage::disk('public')->assertExists($this->business->fresh()->logo_path);
        Storage::disk('public')->assertExists($this->business->fresh()->background_path);

        $this->actingAsApi($this->owner, [])->putJson('/api/business/card', [
            'step' => 'categories',
            'data' => ['category_ids' => [$category->id]],
        ])->assertOk()
            ->assertJsonPath('categories.0.name', 'Bebidas');

        $this->actingAsApi($this->owner, [])->putJson('/api/business/card', [
            'step' => 'products',
            'data' => ['products' => [[
                'id' => $productId,
                'name' => 'Latte',
                'description' => 'Doble espresso con leche',
                'price' => 65.50,
                'category_id' => $category->id,
                'image_url' => $this->imageDataUri,
                'position' => 2,
                'available' => true,
            ]]],
        ])->assertOk();

        $product = Product::with('images')->findOrFail($productId);
        $this->assertSame($this->business->getKey(), $product->business_id);
        $this->assertSame($category->id, $product->category_id);
        $this->assertNotNull($product->menu_category_id);
        $this->assertSame(2, $product->sort_order);
        $this->assertTrue($product->is_available);
        $this->assertCount(1, $product->images);
        $productImagePath = $product->images->first()->path;
        Storage::disk('public')->assertExists($productImagePath);

        $this->actingAsApi($this->owner, [])->putJson('/api/business/card', [
            'step' => 'products',
            'data' => ['products' => [[
                'id' => $productId,
                'name' => 'Latte',
                'description' => 'Doble espresso con leche',
                'price' => '65.50',
                'category_id' => (string) $category->id,
                'image_url' => 'http://127.0.0.1:8000/storage/'.$productImagePath,
                'position' => 2,
                'available' => true,
            ]]],
        ])->assertOk();

        $this->assertSame($productImagePath, $product->fresh()->images()->first()->path);
        Storage::disk('public')->assertExists($productImagePath);

        $this->getJson('/api/public/business/'.$this->business->slug.'/menu')
            ->assertOk()
            ->assertJsonPath('data.0.source_category_id', $category->id)
            ->assertJsonPath('data.0.products.0.id', $productId);

        $this->actingAsApi($this->owner, [])->putJson('/api/business/card', [
            'step' => 'design',
            'data' => [
                'primary_color' => '#123456',
                'secondary_color' => '#ABCDEF',
                'font_family' => 'Inter',
                'menu_style' => 'grid',
            ],
        ])->assertOk()
            ->assertJsonPath('menu_design.primary_color', '#123456')
            ->assertJsonPath('menu_design.menu_style', 'grid');
    }

    public function test_selector_de_paso_es_obligatorio_y_productos_deben_usar_categorias_del_negocio(): void
    {
        $category = Category::create(['name' => 'Postres']);
        $payload = [
            'step' => 'products',
            'data' => ['products' => [[
                'id' => (string) Str::uuid(),
                'name' => 'Pastel',
                'category_id' => $category->id,
                'image_url' => $this->imageDataUri,
            ]]],
        ];

        $this->actingAsApi($this->owner, [])->putJson('/api/business/card', $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('products.products.0.category_id');

        $this->actingAsApi($this->owner, [])->putJson('/api/business/card', [
            'information' => ['name' => 'Sin selector'],
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('step');
    }
}
