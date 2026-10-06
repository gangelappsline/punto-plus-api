<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Credenciales incorrectas en /api/auth/login (modo degradado sin cliente
 * OAuth2 password grant, donde la verificación la hace la propia API).
 */
class InvalidCredentialsException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('Las credenciales no son correctas.');
    }
}
