<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\CreatesOAuthClients;
use Tests\TestCase;

class BusinessQrTest extends TestCase
{
    use CreatesOAuthClients, RefreshDatabase;

    private User $owner;

    private Business $business;

    protected function setUp(): void
    {
        parent::setUp();

        $this->createOAuthClients();
        Storage::fake('public');
        $this->owner = User::factory()->negocio()->create();
        $this->business = Business::factory()->ownedBy($this->owner)->create([
            'slug' => 'cafe-aurora',
            'website' => 'https://cafe-aurora.example',
        ]);
    }

    public function test_negocio_consulta_y_actualiza_la_configuracion_profesional_de_su_qr(): void
    {
        $this->actingAsApi($this->owner, [])->getJson('/api/business/qr/settings')
            ->assertOk()
            ->assertJsonPath('data.settings.destination_type', 'menu')
            ->assertJsonPath('data.settings.module_style', 'rounded')
            ->assertJsonPath('data.settings.size', 1024)
            ->assertJsonPath('data.target_url', url('/api/public/business/cafe-aurora/menu'))
            ->assertJsonPath('data.logo_available', false);

        $this->actingAsApi($this->owner, [])->putJson('/api/business/qr/settings', [
            'size' => 768,
            'foreground_color' => '#102030',
            'background_color' => '#FFFFFF',
            'eye_color' => '#0B57D0',
            'module_style' => 'dots',
            'eye_style' => 'circle',
            'destination_type' => 'custom',
            'custom_url' => 'https://example.com/menu',
        ])->assertOk()
            ->assertJsonPath('data.settings.size', 768)
            ->assertJsonPath('data.settings.module_style', 'dots')
            ->assertJsonPath('data.settings.eye_style', 'circle')
            ->assertJsonPath('data.target_url', 'https://example.com/menu');

        $this->assertSame('dots', $this->business->fresh()->qr_settings['module_style']);
    }

    public function test_la_configuracion_es_independiente_por_negocio(): void
    {
        $otherOwner = User::factory()->negocio()->create();
        $otherBusiness = Business::factory()->ownedBy($otherOwner)->create(['slug' => 'panaderia-del-sol']);

        $this->actingAsApi($otherOwner, [])->getJson('/api/business/qr/settings')
            ->assertOk()
            ->assertJsonPath('data.target_url', url('/api/public/business/panaderia-del-sol/menu'));

        $this->actingAsApi($this->owner, [])->putJson('/api/business/qr/settings', [
            'destination_type' => 'custom',
            'custom_url' => 'https://example.com/cafe-aurora',
            'business_id' => $otherBusiness->getKey(),
        ])->assertOk();

        $this->assertSame('custom', $this->business->fresh()->qr_settings['destination_type']);
        $this->assertNull($otherBusiness->fresh()->qr_settings);
    }

    public function test_el_qr_rechaza_colores_sin_contraste_y_destinos_incompletos(): void
    {
        $this->actingAsApi($this->owner, [])->putJson('/api/business/qr/settings', [
            'foreground_color' => '#EEEEEE',
            'background_color' => '#FFFFFF',
        ])->assertUnprocessable()->assertJsonValidationErrors('foreground_color');

        $this->actingAsApi($this->owner, [])->putJson('/api/business/qr/settings', [
            'destination_type' => 'custom',
        ])->assertUnprocessable()->assertJsonValidationErrors('custom_url');

        $this->actingAsApi($this->owner, [])->putJson('/api/business/qr/settings', [
            'include_logo' => true,
        ])->assertUnprocessable()->assertJsonValidationErrors('include_logo');
    }

    public function test_la_vista_previa_es_un_svg_estilizado(): void
    {
        $this->actingAsApi($this->owner, [])->get('/api/business/qr/preview.svg')
            ->assertOk()
            ->assertHeader('content-type', 'image/svg+xml; charset=UTF-8')
            ->assertSee('<svg', false);
    }

    public function test_las_descargas_png_y_webp_se_generan_con_gd(): void
    {
        if (! extension_loaded('gd')) {
            $this->markTestSkipped('GD no está instalado en el runtime de pruebas.');
        }

        $png = $this->actingAsApi($this->owner, [])->get('/api/business/qr/download/png')->assertOk();
        $this->assertSame('image/png', $png->headers->get('content-type'));
        $this->assertSame(IMAGETYPE_PNG, getimagesizefromstring($png->getContent())[2]);

        if (! function_exists('imagewebp')) {
            $this->markTestSkipped('GD no tiene soporte WebP en el runtime de pruebas.');
        }

        $webp = $this->actingAsApi($this->owner, [])->get('/api/business/qr/download/webp')->assertOk();
        $this->assertSame('image/webp', $webp->headers->get('content-type'));
        $this->assertSame(IMAGETYPE_WEBP, getimagesizefromstring($webp->getContent())[2]);
    }
}
