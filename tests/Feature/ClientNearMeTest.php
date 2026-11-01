<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\CustomerCard;
use App\Models\LoyaltyCard;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesOAuthClients;
use Tests\TestCase;

/**
 * `GET /api/client/near-me`: negocios dentro del radio (10 km por defecto)
 * alrededor de la `latitude`/`longitude` recibidas.
 */
class ClientNearMeTest extends TestCase
{
    use CreatesOAuthClients, RefreshDatabase;

    /** Centro de búsqueda: Zócalo de la Ciudad de México. */
    private const LATITUDE = 19.4326;

    private const LONGITUDE = -99.1332;

    private User $cliente;

    private Business $nearby;

    private Business $far;

    protected function setUp(): void
    {
        parent::setUp();

        $this->createOAuthClients();

        $this->cliente = User::factory()->cliente()->create();

        // ~0,5 km al norte del centro.
        $this->nearby = Business::factory()->create([
            'name' => 'Café de la esquina',
            'category' => 'cafetería',
            'latitude' => self::LATITUDE + 0.005,
            'longitude' => self::LONGITUDE,
        ]);

        // ~19 km al norte: fuera del radio de 10 km.
        $this->far = Business::factory()->create([
            'name' => 'Café del norte',
            'category' => 'cafetería',
            'latitude' => self::LATITUDE + 0.17,
            'longitude' => self::LONGITUDE,
        ]);
    }

    public function test_devuelve_solo_los_negocios_dentro_de_diez_kilometros(): void
    {
        $response = $this->nearMe();

        $response->assertOk()->assertJsonCount(1, 'data');
        $this->assertSame('Café de la esquina', $response->json('data.0.name'));

        $distance = (float) $response->json('data.0.distance_km');
        $this->assertGreaterThan(0.4, $distance);
        $this->assertLessThan(0.7, $distance);
    }

    public function test_ordena_por_distancia_y_expone_el_centro_y_el_radio(): void
    {
        Business::factory()->create([
            'name' => 'Muy cerca',
            'category' => 'cafetería',
            'latitude' => self::LATITUDE + 0.001,
            'longitude' => self::LONGITUDE,
        ]);

        $response = $this->nearMe();

        $response->assertOk()
            ->assertJsonPath('center.latitude', self::LATITUDE)
            ->assertJsonPath('center.longitude', self::LONGITUDE)
            ->assertJsonPath('center.radius_km', 10.0)
            ->assertJsonPath('data.0.name', 'Muy cerca')
            ->assertJsonPath('data.1.name', 'Café de la esquina');

        $this->assertSame(2, $response->json('meta.total'));
    }

    public function test_permite_ampliar_el_radio(): void
    {
        $response = $this->nearMe(['radius' => 25]);

        $response->assertOk()->assertJsonCount(2, 'data');
        $this->assertSame(
            ['Café de la esquina', 'Café del norte'],
            array_column($response->json('data'), 'name'),
        );
    }

    public function test_ignora_negocios_inactivos_o_sin_coordenadas(): void
    {
        Business::factory()->inactive()->create([
            'name' => 'Local cerrado',
            'category' => 'cafetería',
            'latitude' => self::LATITUDE + 0.002,
            'longitude' => self::LONGITUDE,
        ]);

        Business::factory()->create([
            'name' => 'Sin coordenadas',
            'category' => 'cafetería',
            'latitude' => null,
            'longitude' => null,
        ]);

        $response = $this->nearMe();

        $response->assertOk()->assertJsonCount(1, 'data');
        $this->assertSame('Café de la esquina', $response->json('data.0.name'));
    }

    public function test_indica_si_el_cliente_ya_tiene_tarjeta_en_el_negocio(): void
    {
        $loyaltyCard = LoyaltyCard::factory()->forBusiness($this->nearby)->create();
        CustomerCard::factory()->forCard($loyaltyCard)->forUser($this->cliente)->create();

        $response = $this->nearMe();

        $response->assertOk()->assertJsonPath('data.0.has_card', true);
        $this->assertEquals(1, $response->json('data.0.active_loyalty_cards_count'));
    }

    public function test_permite_filtrar_por_texto_y_categoria(): void
    {
        Business::factory()->create([
            'name' => 'Panadería cerca',
            'category' => 'panadería',
            'latitude' => self::LATITUDE + 0.003,
            'longitude' => self::LONGITUDE,
        ]);

        $this->nearMe(['search' => 'panadería'])->assertOk()->assertJsonCount(1, 'data');

        $this->nearMe(['category' => 'panadería'])
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Panadería cerca');
    }

    public function test_pagina_los_resultados(): void
    {
        Business::factory()->create([
            'name' => 'Segundo cercano',
            'category' => 'cafetería',
            'latitude' => self::LATITUDE + 0.006,
            'longitude' => self::LONGITUDE,
        ]);

        $response = $this->nearMe(['per_page' => 1, 'page' => 2]);

        $response->assertOk()->assertJsonCount(1, 'data');
        $this->assertSame(2, $response->json('meta.total'));
        $this->assertSame('Segundo cercano', $response->json('data.0.name'));
    }

    public function test_exige_las_coordenadas(): void
    {
        $this->actingAsApi($this->cliente)
            ->getJson('/api/client/near-me')
            ->assertStatus(422)
            ->assertJsonValidationErrors(['latitude', 'longitude']);
    }

    public function test_valida_el_rango_de_las_coordenadas(): void
    {
        $this->actingAsApi($this->cliente)
            ->getJson('/api/client/near-me?latitude=120&longitude=300')
            ->assertStatus(422)
            ->assertJsonValidationErrors(['latitude', 'longitude']);
    }

    public function test_requiere_autenticacion(): void
    {
        $this->getJson('/api/client/near-me?latitude=19.4&longitude=-99.1')->assertUnauthorized();
    }

    /**
     * Llama al endpoint con el centro de búsqueda de la prueba.
     *
     * @param  array<string, mixed>  $extra
     * @return \Illuminate\Testing\TestResponse<\Illuminate\Http\Response>
     */
    private function nearMe(array $extra = [])
    {
        $query = http_build_query(array_merge([
            'latitude' => self::LATITUDE,
            'longitude' => self::LONGITUDE,
        ], $extra));

        return $this->actingAsApi($this->cliente)->getJson('/api/client/near-me?'.$query);
    }
}
