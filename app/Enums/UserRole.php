<?php

namespace App\Enums;

enum UserRole: string
{
    case Cliente = 'client';
    case Negocio = 'business';
    case Admin = 'admin';

    /**
     * Scope OAuth2 que corresponde al rol.
     */
    public function scope(): string
    {
        return match ($this) {
            self::Cliente => 'client',
            self::Negocio => 'business',
            self::Admin => 'admin',
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Cliente => 'Cliente',
            self::Negocio => 'Negocio',
            self::Admin => 'Administrador',
        };
    }

    /**
     * Roles que pueden crearse desde el registro público (admin no).
     *
     * @return array<int, self>
     */
    public static function selfAssignable(): array
    {
        return [self::Cliente, self::Negocio];
    }

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_map(fn (self $role): string => $role->value, self::cases());
    }
}
