<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\MenuCategory;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\CreatesOAuthClients;
use Tests\TestCase;

class MenuTest extends TestCase
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
        $this->business = Business::factory()->ownedBy($this->owner)->create(['slug' => 'cafe-aurora']);
        $this->imageDataUri = 'data:image/png;base64,'.base64_encode(
            (string) file_get_contents(base_path('tests/Fixtures/images/logo.png')),
        );
    }

    public function test_negocio_administra_menu_productos_presentaciones_y_fotos_publicas(): void
    {
        $categoryResponse = $this->actingAsApi($this->owner, [])->postJson('/api/business/menu/categories', [
            'name' => 'Bebidas',
            'sort_order' => 1,
        ]);

        $categoryResponse->assertCreated()->assertJsonPath('data.name', 'Bebidas');
        $categoryId = $categoryResponse->json('data.id');

        $productResponse = $this->actingAsApi($this->owner, [])->postJson(
            '/api/business/menu/categories/'.$categoryId.'/products',
            [
                'name' => 'Coca Cola',
                'description' => 'Refresco clásico',
                'price' => 25.00,
                'images' => [$this->imageDataUri, $this->imageDataUri],
            ],
        );

        $productResponse->assertCreated()
            ->assertJsonPath('data.name', 'Coca Cola')
            ->assertJsonCount(2, 'data.images');

        $productId = $productResponse->json('data.id');
        $product = Product::with('images')->findOrFail($productId);
        $this->assertCount(2, $product->images);

        foreach ($product->images as $image) {
            Storage::disk('public')->assertExists($image->path);
        }

        $this->actingAsApi($this->owner, [])->postJson(
            '/api/business/menu/products/'.$productId.'/presentations',
            ['name' => '600 ml'],
        )->assertCreated()
            ->assertJsonPath('data.price', null)
            ->assertJsonPath('data.effective_price', '25.00')
            ->assertJsonPath('data.inherits_product_price', true);

        $this->actingAsApi($this->owner, [])->postJson(
            '/api/business/menu/products/'.$productId.'/presentations',
            ['name' => '350 ml', 'price' => 18.50],
        )->assertCreated()
            ->assertJsonPath('data.price', '18.50')
            ->assertJsonPath('data.effective_price', '18.50')
            ->assertJsonPath('data.inherits_product_price', false);

        $this->getJson('/api/public/business/'.$this->business->slug.'/menu')
            ->assertOk()
            ->assertJsonPath('data.0.name', 'Bebidas')
            ->assertJsonPath('data.0.products.0.name', 'Coca Cola')
            ->assertJsonCount(2, 'data.0.products.0.images')
            ->assertJsonCount(2, 'data.0.products.0.presentations');

        $paths = $product->images->pluck('path')->all();
        $this->actingAsApi($this->owner, [])->deleteJson('/api/business/menu/products/'.$productId)->assertOk();

        foreach ($paths as $path) {
            Storage::disk('public')->assertMissing($path);
        }
    }

    public function test_producto_requiere_de_una_a_cinco_imagenes_base64(): void
    {
        $category = MenuCategory::create([
            'business_id' => $this->business->getKey(),
            'name' => 'Postres',
        ]);
        $endpoint = '/api/business/menu/categories/'.$category->id.'/products';

        $this->actingAsApi($this->owner, [])->postJson($endpoint, ['name' => 'Pastel'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('images');

        $this->actingAsApi($this->owner, [])->postJson($endpoint, [
            'name' => 'Pastel',
            'images' => array_fill(0, 6, $this->imageDataUri),
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('images');
    }

    public function test_un_negocio_no_puede_consultar_categorias_de_menu_de_otro_negocio(): void
    {
        $category = MenuCategory::create([
            'business_id' => $this->business->getKey(),
            'name' => 'Bebidas',
        ]);

        $otherOwner = User::factory()->negocio()->create();
        Business::factory()->ownedBy($otherOwner)->create();

        $this->actingAsApi($otherOwner, [])->getJson('/api/business/menu/categories/'.$category->id)->assertNotFound();
    }
}
