<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\Promotion;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\CreatesOAuthClients;
use Tests\TestCase;

/**
 * `POST/PUT /api/business/promotions`: el formulario del negocio no siempre envía
 * `schedule_type` ni `days_of_week`, y el banner llega en base64.
 */
class BusinessPromotionTest extends TestCase
{
    use CreatesOAuthClients, RefreshDatabase;

    private User $owner;

    private Business $business;

    private string $bannerBase64;

    protected function setUp(): void
    {
        parent::setUp();

        $this->createOAuthClients();
        Storage::fake('public');

        $this->owner = User::factory()->negocio()->create();
        $this->business = Business::factory()->ownedBy($this->owner)->create(['slug' => 'cafe-aurora']);
        $this->bannerBase64 = 'data:image/png;base64,'.base64_encode(
            (string) file_get_contents(base_path('tests/Fixtures/images/logo.png')),
        );
    }

    public function test_promocion_sin_fechas_ni_schedule_type_queda_como_indefinida(): void
    {
        // Payload tal cual lo manda el formulario del negocio.
        $response = $this->actingAsApi($this->owner)->postJson('/api/business/promotions', [
            'title' => 'Regalo de bienvenida',
            'description' => 'Muestra la promoción al registrarte',
            'loyalty_card_id' => null,
            'discount_type' => 'gift',
            'discount_value' => null,
            'code' => null,
            'terms' => null,
            'starts_at' => null,
            'ends_at' => null,
            'max_redemptions' => 2,
            'is_active' => true,
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.schedule_type', 'indefinite')
            ->assertJsonPath('data.starts_at', null)
            ->assertJsonPath('data.ends_at', null)
            ->assertJsonPath('data.days_of_week', []);

        $this->assertDatabaseHas('promotions', [
            'title' => 'Regalo de bienvenida',
            'schedule_type' => 'indefinite',
            'starts_at' => null,
            'ends_at' => null,
        ]);
    }

    public function test_las_fechas_solo_se_exigen_cuando_hay_rango_de_fechas(): void
    {
        $response = $this->actingAsApi($this->owner)->postJson('/api/business/promotions', [
            'title' => 'Temporada',
            'discount_type' => 'percentage',
            'discount_value' => 20,
            'schedule_type' => 'date_range',
        ]);

        $response->assertUnprocessable()->assertJsonValidationErrors(['starts_at', 'ends_at']);

        // Con una sola fecha se deduce `date_range` y falta la otra.
        $partial = $this->actingAsApi($this->owner)->postJson('/api/business/promotions', [
            'title' => 'Temporada',
            'discount_type' => 'percentage',
            'discount_value' => 20,
            'starts_at' => '2026-12-01',
        ]);

        $partial->assertUnprocessable()->assertJsonValidationErrors('ends_at');

        $complete = $this->actingAsApi($this->owner)->postJson('/api/business/promotions', [
            'title' => 'Temporada',
            'discount_type' => 'percentage',
            'discount_value' => 20,
            'starts_at' => '2026-12-01',
            'ends_at' => '2026-12-24',
        ]);

        $complete->assertCreated()
            ->assertJsonPath('data.schedule_type', 'date_range');

        $this->assertNotNull($complete->json('data.starts_at'));
        $this->assertNotNull($complete->json('data.ends_at'));
    }

    public function test_las_horas_deducen_el_rango_de_hora_sin_exigir_dias(): void
    {
        $response = $this->actingAsApi($this->owner)->postJson('/api/business/promotions', [
            'title' => 'Hora feliz',
            'discount_type' => 'free_item',
            'start_time' => '18:00',
            'end_time' => '21:00',
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.schedule_type', 'time_range')
            ->assertJsonPath('data.start_time', '18:00')
            ->assertJsonPath('data.end_time', '21:00');
    }

    public function test_el_banner_base64_se_guarda_en_el_disco_publico(): void
    {
        $response = $this->actingAsApi($this->owner)->postJson('/api/business/promotions', [
            'title' => 'Con banner',
            'discount_type' => 'gift',
            'banner' => $this->bannerBase64,
        ]);

        $response->assertCreated();

        $path = $response->json('data.image_path');
        $promotionId = $response->json('data.id');

        $this->assertIsString($path);
        $this->assertStringStartsWith('promotions/'.$promotionId.'/banner/', $path);
        $this->assertStringEndsWith('.png', $path);
        Storage::disk('public')->assertExists($path);
        $this->assertNotNull($response->json('data.image_url'));

        $this->assertDatabaseHas('promotions', ['id' => $promotionId, 'image_path' => $path]);
    }

    public function test_un_banner_invalido_responde_422(): void
    {
        $response = $this->actingAsApi($this->owner)->postJson('/api/business/promotions', [
            'title' => 'Con banner roto',
            'discount_type' => 'gift',
            'banner' => 'data:image/png;base64,esto-no-es-una-imagen',
        ]);

        $response->assertUnprocessable()->assertJsonValidationErrors('banner');
    }

    public function test_el_banner_se_reemplaza_y_se_elimina(): void
    {
        $promotion = Promotion::factory()->forBusiness($this->business)->indefinite()->create();

        $created = $this->actingAsApi($this->owner)->putJson('/api/business/promotions/'.$promotion->id, [
            'banner' => $this->bannerBase64,
        ]);

        $created->assertOk();
        $firstPath = $created->json('data.image_path');
        Storage::disk('public')->assertExists($firstPath);

        $replaced = $this->actingAsApi($this->owner)->putJson('/api/business/promotions/'.$promotion->id, [
            'banner' => $this->bannerBase64,
        ]);

        $replaced->assertOk();
        $secondPath = $replaced->json('data.image_path');

        $this->assertNotSame($firstPath, $secondPath);
        Storage::disk('public')->assertMissing($firstPath);
        Storage::disk('public')->assertExists($secondPath);

        $removed = $this->actingAsApi($this->owner)->putJson('/api/business/promotions/'.$promotion->id, [
            'remove_banner' => true,
        ]);

        $removed->assertOk()->assertJsonPath('data.image_path', null);
        Storage::disk('public')->assertMissing($secondPath);
    }

    public function test_editar_una_promocion_indefinida_reenviando_las_fechas_en_null_sigue_funcionando(): void
    {
        $promotion = Promotion::factory()->forBusiness($this->business)->indefinite()->create();

        $response = $this->actingAsApi($this->owner)->putJson('/api/business/promotions/'.$promotion->id, [
            'title' => 'Martes de 2x1',
            'description' => null,
            'loyalty_card_id' => null,
            'discount_type' => 'gift',
            'discount_value' => null,
            'code' => null,
            'terms' => null,
            'starts_at' => null,
            'ends_at' => null,
            'max_redemptions' => 2,
            'is_active' => true,
        ]);

        $response->assertOk()
            ->assertJsonPath('data.title', 'Martes de 2x1')
            ->assertJsonPath('data.schedule_type', 'indefinite')
            ->assertJsonPath('data.starts_at', null)
            ->assertJsonPath('data.ends_at', null);
    }
}
