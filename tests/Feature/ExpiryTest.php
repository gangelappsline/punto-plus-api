<?php

namespace Tests\Feature;

use App\Enums\CardStatus;
use App\Models\Business;
use App\Models\CustomerCard;
use App\Models\LoyaltyCard;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesOAuthClients;
use Tests\TestCase;

class ExpiryTest extends TestCase
{
    use CreatesOAuthClients, RefreshDatabase;

    private Business $business;

    protected function setUp(): void
    {
        parent::setUp();

        $this->createOAuthClients();

        $this->business = Business::factory()
            ->ownedBy(User::factory()->negocio()->create())
            ->create();
    }

    public function test_el_comando_caduca_las_tarjetas_de_programas_vencidos(): void
    {
        $vencido = LoyaltyCard::factory()->forBusiness($this->business)->create([
            'expires_at' => now()->subDay(),
        ]);

        $vigente = LoyaltyCard::factory()->forBusiness($this->business)->create([
            'expires_at' => now()->addMonth(),
        ]);

        $tarjetaVencida = CustomerCard::factory()->forCard($vencido, 2)->create();
        $tarjetaVigente = CustomerCard::factory()->forCard($vigente, 2)->create();

        $this->artisan('punto-plus:expire-cards')->assertSuccessful();

        $this->assertSame(CardStatus::Expired, $tarjetaVencida->fresh()->status);
        $this->assertSame(CardStatus::Active, $tarjetaVigente->fresh()->status);
    }

    public function test_una_tarjeta_completada_pendiente_de_canje_no_caduca(): void
    {
        // El programa sigue vigente aunque la tarjeta haya completado el ciclo.
        $programa = LoyaltyCard::factory()->forBusiness($this->business)->create([
            'required_stamps' => 3,
            'expires_at' => now()->addMonth(),
        ]);

        $completada = CustomerCard::factory()->forCard($programa, 3)->create();

        $this->artisan('punto-plus:expire-cards')->assertSuccessful();

        $this->assertSame(CardStatus::Completed, $completada->fresh()->status);
        $this->assertSame(3, $completada->fresh()->stamps_count);
    }

    public function test_los_sellos_caducan_tras_la_vigencia_configurada_en_la_tarjeta(): void
    {
        $programa = LoyaltyCard::factory()->forBusiness($this->business)->create([
            'required_stamps' => 10,
            'settings' => ['stamp_icon' => 'coffee', 'stamps_lifetime_days' => 30],
        ]);

        $antigua = CustomerCard::factory()->forCard($programa, 4)->create([
            'stamps_count' => 4,
            'last_stamp_at' => now()->subDays(31),
        ]);

        $reciente = CustomerCard::factory()->forCard($programa, 4)->create([
            'stamps_count' => 4,
            'last_stamp_at' => now()->subDays(29),
        ]);

        $this->artisan('punto-plus:expire-cards')->assertSuccessful();

        $this->assertSame(0, $antigua->fresh()->stamps_count);
        $this->assertSame(4, $reciente->fresh()->stamps_count);
    }

    public function test_sin_configuracion_los_sellos_no_caducan(): void
    {
        config(['punto_plus.cards.stamps_lifetime_days' => null]);

        $programa = LoyaltyCard::factory()->forBusiness($this->business)->create([
            'settings' => ['stamp_icon' => 'star'],
        ]);

        $tarjeta = CustomerCard::factory()->forCard($programa, 4)->create([
            'stamps_count' => 4,
            'last_stamp_at' => now()->subYear(),
        ]);

        $this->artisan('punto-plus:expire-cards')->assertSuccessful();

        $this->assertSame(4, $tarjeta->fresh()->stamps_count);
    }

    public function test_la_simulacion_no_escribe_nada(): void
    {
        $programa = LoyaltyCard::factory()->forBusiness($this->business)->create([
            'expires_at' => now()->subDay(),
        ]);

        $tarjeta = CustomerCard::factory()->forCard($programa, 3)->create([
            'stamps_count' => 3,
            'last_stamp_at' => now()->subDays(90),
            'expires_at' => now()->subDay(),
        ]);

        $this->artisan('punto-plus:expire-cards', ['--dry-run' => true])->assertSuccessful();

        $this->assertSame(CardStatus::Active, $tarjeta->fresh()->status);
        $this->assertSame(3, $tarjeta->fresh()->stamps_count);
    }

    public function test_al_volver_a_escanear_el_qr_una_tarjeta_caducada_se_reactiva(): void
    {
        $programa = LoyaltyCard::factory()->forBusiness($this->business)->create([
            'join_code' => 'CAFE2026',
            'required_stamps' => 6,
            'expires_at' => null,
        ]);

        $cliente = User::factory()->cliente()->create();

        $tarjeta = CustomerCard::factory()->forUser($cliente)->forCard($programa, 3)->create([
            'status' => CardStatus::Expired,
            'expires_at' => now()->subWeek(),
        ]);

        $this->actingAsApi($cliente)
            ->postJson('/api/customer/cards/join', ['code' => 'CAFE2026'])
            ->assertOk()
            ->assertJsonPath('already_joined', true)
            ->assertJsonPath('data.status', 'active')
            ->assertJsonPath('data.stamps_count', 3);

        $this->assertSame(CardStatus::Active, $tarjeta->fresh()->status);
        $this->assertSame(3, $tarjeta->fresh()->stamps_count);
        $this->assertNull($tarjeta->fresh()->expires_at);
    }
}
