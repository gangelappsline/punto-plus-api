<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * La emisión de tokens OAuth2 no está configurada (faltan claves o clientes).
 * Se traduce a un HTTP 503 con instrucciones para el operador.
 */
class OAuthConfigurationException extends RuntimeException
{
    public static function missingPasswordClient(): self
    {
        return new self(
            'No hay un cliente OAuth2 de tipo "password grant" configurado. '.
            'Ejecuta: php artisan punto-plus:oauth-clients --write-env'
        );
    }

    public static function missingKeys(): self
    {
        return new self(
            'Laravel Passport no tiene claves RSA. Ejecuta: php artisan passport:install'
        );
    }
}
