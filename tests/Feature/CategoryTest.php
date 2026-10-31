<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\CreatesOAuthClients;
use Tests\TestCase;

class CategoryTest extends TestCase
{
    use CreatesOAuthClients, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->createOAuthClients();
        Storage::fake('public');
    }

    public function test_admin_puede_crear_actualizar_listar_y_eliminar_categorias(): void
    {
        $admin = User::factory()->admin()->create();
        $icon = UploadedFile::fake()->createWithContent(
            'food.png',
            (string) file_get_contents(base_path('tests/Fixtures/images/logo.png')),
        );

        $created = $this->actingAsApi($admin)->post('/api/admin/categories', [
            'name' => 'Restaurantes',
            'icon' => $icon,
        ]);

        $created->assertCreated()
            ->assertJsonPath('data.name', 'Restaurantes')
            ->assertJsonPath('data.icon', fn ($url) => is_string($url) && str_contains($url, 'categories/'));

        $category = Category::firstOrFail();
        Storage::disk('public')->assertExists($category->icon);

        $this->actingAsApi($admin)
            ->getJson('/api/admin/categories')
            ->assertOk()
            ->assertJsonPath('data.0.id', $category->id);

        $this->actingAsApi($admin)
            ->putJson('/api/admin/categories/'.$category->id, ['name' => 'Comida'])
            ->assertOk()
            ->assertJsonPath('data.name', 'Comida');

        $this->actingAsApi($admin)
            ->deleteJson('/api/admin/categories/'.$category->id)
            ->assertOk();

        $this->assertDatabaseMissing('categories', ['id' => $category->id]);
        Storage::disk('public')->assertMissing($category->icon);
    }

    public function test_la_ruta_publica_lista_categorias_sin_autenticacion(): void
    {
        $category = Category::create(['name' => 'Cafeterías']);

        $this->getJson('/api/categories')
            ->assertOk()
            ->assertJsonPath('data.0.id', $category->id)
            ->assertJsonPath('data.0.name', 'Cafeterías')
            ->assertJsonPath('data.0.icon', null);
    }

    public function test_solo_un_administrador_puede_gestionar_categorias(): void
    {
        $cliente = User::factory()->cliente()->create();

        $this->actingAsApi($cliente)
            ->postJson('/api/admin/categories', ['name' => 'Cafeterías'])
            ->assertForbidden();
    }
}
