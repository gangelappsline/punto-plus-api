<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesOAuthClients;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use CreatesOAuthClients, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->createOAuthClients();
    }

    public function test_un_cliente_puede_registrarse_y_recibe_tokens_oauth2(): void
    {
        $response = $this->postJson('/api/auth/register', [
            'name' => 'Marta Ruiz',
            'email' => 'Marta@Example.com',
            'password' => 'Password123!',
            'password_confirmation' => 'Password123!',
            'role' => 'cliente',
            'device_name' => 'iPhone de Marta',
        ]);

        $response->assertCreated()
            ->assertJsonPath('user.role', 'cliente')
            ->assertJsonPath('user.email', 'marta@example.com')
            ->assertJsonStructure([
                'message',
                'user' => ['id', 'name', 'email', 'role'],
                'authorization' => ['access_token', 'refresh_token', 'token_type', 'expires_in', 'scope'],
            ]);

        $this->assertDatabaseHas('users', ['email' => 'marta@example.com', 'role' => 'cliente']);
        $this->assertSame('cliente', $response->json('authorization.scope'));
    }

    public function test_un_negocio_puede_registrarse_con_su_ficha_de_negocio(): void
    {
        $response = $this->postJson('/api/auth/register', [
            'name' => 'Lucía Gómez',
            'email' => 'lucia@example.com',
            'password' => 'Password123!',
            'password_confirmation' => 'Password123!',
            'role' => 'negocio',
            'business' => [
                'name' => 'Café Aurora',
                'category' => 'cafetería',
                'city' => 'Valencia',
                'country' => 'es',
            ],
        ]);

        $response->assertCreated()->assertJsonPath('user.business.name', 'Café Aurora');

        $this->assertDatabaseHas('businesses', [
            'name' => 'Café Aurora',
            'slug' => 'cafe-aurora',
            'country' => 'ES',
        ]);

        $owner = User::where('email', 'lucia@example.com')->firstOrFail();

        $this->assertSame('negocio', $response->json('authorization.scope'));
        $this->assertTrue($owner->ownsBusiness(Business::where('name', 'Café Aurora')->first()));
    }

    public function test_el_registro_no_permite_elegir_el_rol_admin(): void
    {
        $this->postJson('/api/auth/register', [
            'name' => 'Intruso',
            'email' => 'intruso@example.com',
            'password' => 'Password123!',
            'password_confirmation' => 'Password123!',
            'role' => 'admin',
        ])->assertStatus(422)->assertJsonValidationErrors('role');
    }

    public function test_login_devuelve_tokens_y_permite_consultar_el_perfil(): void
    {
        $user = User::factory()->cliente()->create([
            'email' => 'cliente@example.com',
            'password' => 'Password123!',
        ]);

        $login = $this->postJson('/api/auth/login', [
            'email' => $user->email,
            'password' => 'Password123!',
            'device_name' => 'Android',
        ]);

        $login->assertOk()->assertJsonStructure(['authorization' => ['access_token', 'refresh_token']]);

        $token = (string) $login->json('authorization.access_token');

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/user')
            ->assertOk()
            ->assertJsonPath('data.email', 'cliente@example.com')
            ->assertJsonPath('data.role', 'cliente');

        $this->assertNotNull($user->fresh()->last_login_at);
    }

    public function test_login_con_password_incorrecta_devuelve_401(): void
    {
        User::factory()->cliente()->create(['email' => 'cliente@example.com']);

        $this->postJson('/api/auth/login', [
            'email' => 'cliente@example.com',
            'password' => 'incorrecta',
        ])->assertStatus(401)->assertJsonPath('error', 'invalid_credentials');
    }

    public function test_login_de_cuenta_desactivada_devuelve_403(): void
    {
        User::factory()->inactive()->create([
            'email' => 'inactivo@example.com',
            'password' => 'Password123!',
        ]);

        $this->postJson('/api/auth/login', [
            'email' => 'inactivo@example.com',
            'password' => 'Password123!',
        ])->assertStatus(403)->assertJsonPath('error', 'account_disabled');
    }

    public function test_las_rutas_protegidas_requieren_token(): void
    {
        $this->getJson('/api/user')->assertStatus(401);
        $this->getJson('/api/customer/cards')->assertStatus(401);
        $this->getJson('/api/business/cards')->assertStatus(401);
    }

    public function test_el_refresh_token_permite_renovar_el_acceso(): void
    {
        $user = User::factory()->cliente()->create([
            'email' => 'refresh@example.com',
            'password' => 'Password123!',
        ]);

        $login = $this->postJson('/api/auth/login', [
            'email' => 'refresh@example.com',
            'password' => 'Password123!',
        ])->assertOk();

        $refreshToken = (string) $login->json('authorization.refresh_token');

        $this->postJson('/api/auth/refresh', ['refresh_token' => $refreshToken])
            ->assertOk()
            ->assertJsonStructure(['authorization' => ['access_token', 'refresh_token']]);
    }

    public function test_logout_revoca_el_token_actual(): void
    {
        $user = User::factory()->cliente()->create([
            'email' => 'logout@example.com',
            'password' => 'Password123!',
        ]);

        $login = $this->postJson('/api/auth/login', [
            'email' => 'logout@example.com',
            'password' => 'Password123!',
        ]);

        $token = (string) $login->json('authorization.access_token');

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/auth/logout')
            ->assertOk();

        $this->assertGreaterThan(
            0,
            \Laravel\Passport\Passport::token()
                ->newQuery()
                ->where('user_id', $user->getAuthIdentifier())
                ->where('revoked', true)
                ->count()
        );
    }
}
