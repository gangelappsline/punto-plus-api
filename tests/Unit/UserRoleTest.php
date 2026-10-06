<?php

namespace Tests\Unit;

use App\Enums\CardStatus;
use App\Enums\RedemptionStatus;
use App\Enums\UserRole;
use Tests\TestCase;

class UserRoleTest extends TestCase
{
    public function test_los_roles_coinciden_con_los_scopes_oauth2(): void
    {
        $this->assertSame('cliente', UserRole::Cliente->value);
        $this->assertSame('negocio', UserRole::Negocio->value);
        $this->assertSame('admin', UserRole::Admin->value);

        foreach (UserRole::cases() as $role) {
            $this->assertContains($role->value, array_keys(config('punto_plus.scopes')));
        }
    }

    public function test_solo_cliente_y_negocio_pueden_autoregistrarse(): void
    {
        $this->assertSame([UserRole::Cliente, UserRole::Negocio], UserRole::selfAssignable());
        $this->assertNotContains(UserRole::Admin, UserRole::selfAssignable());
    }

    public function test_las_etiquetas_estan_en_espanol(): void
    {
        $this->assertSame('Cliente', UserRole::Cliente->label());
        $this->assertStringStartsWith('Completada', CardStatus::Completed->label());
        $this->assertStringStartsWith('Pendiente', RedemptionStatus::Pending->label());
    }

    public function test_el_estado_de_la_tarjeta_determina_si_se_puede_sellar(): void
    {
        $this->assertTrue(CardStatus::Active->isUsable());
        $this->assertTrue(CardStatus::Completed->isUsable());
        $this->assertFalse(CardStatus::Blocked->isUsable());
        $this->assertFalse(CardStatus::Expired->isUsable());

        $this->assertTrue(RedemptionStatus::Pending->isOpen());
        $this->assertFalse(RedemptionStatus::Completed->isOpen());
    }
}
