<?php

/*
|--------------------------------------------------------------------------
| Configuración de Punto Plus
|--------------------------------------------------------------------------
|
| Valores propios del dominio de fidelidad. Todo lo que aquí se define puede
| sobreescribirse vía variables de entorno (.env) o config:cache en despliegue.
|
*/

return [

    /*
    |--------------------------------------------------------------------------
    | OAuth2 / Passport
    |--------------------------------------------------------------------------
    */

    'oauth' => [
        /*
         | Cliente "password grant" que la API usa internamente para emitir
         | tokens desde POST /api/auth/login y POST /api/auth/refresh.
         | Se genera con `php artisan punto-plus:oauth-clients`.
         */
        'password_client_id' => env('PASSPORT_PASSWORD_CLIENT_ID'),
        'password_client_secret' => env('PASSPORT_PASSWORD_CLIENT_SECRET'),

        /*
         | Cuando es true, los tokens se emiten dentro del mismo proceso (sin
         | petición HTTP al propio servidor). Si el stack PSR-7 no estuviera
         | disponible se cae automáticamente al endpoint HTTP /oauth/token.
         */
        'internal_dispatch' => env('OAUTH_INTERNAL_DISPATCH', true),

        /*
         | URL absoluta del endpoint de tokens. Si es null se usa url('/oauth/token').
         | Importante: en producción debe ser accesible desde el propio servidor.
         */
        'token_url' => env('OAUTH_TOKEN_URL'),

        /*
         | Redirect URIs registradas para la app móvil (Authorization Code + PKCE).
         */
        'mobile_redirect_uris' => array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env('OAUTH_MOBILE_REDIRECT_URIS', 'puntoplus://oauth/callback,https://localhost/oauth/callback'))
        ))),

        /*
         | Sólo se habilita el password grant si no existe app móvil usando PKCE.
         | Desactivado por defecto en Passport 13 (buena práctica OAuth2 2.1).
         */
        'password_grant_enabled' => env('OAUTH_PASSWORD_GRANT_ENABLED', true),

        /*
         | Ruta alternativa de claves RSA (por defecto storage/*.key).
         | Se usa en la suite de pruebas con las claves de tests/Fixtures/keys.
         */
        'keys_path' => env('PASSPORT_KEYS_PATH'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Scopes OAuth2
    |--------------------------------------------------------------------------
    |
    | El scope determina qué área de la API puede consumir el token. Cada rol
    | recibe el scope con su nombre al autenticarse.
    |
    */

    'scopes' => [
        'cliente' => 'Acceso a tarjetas de fidelidad, sellos y canjes del cliente autenticado.',
        'negocio' => 'Administración del negocio: tarjetas, promociones, recompensas, escaneos y canjes.',
        'admin' => 'Administración global de la plataforma Punto Plus.',
    ],

    'default_scope' => env('OAUTH_DEFAULT_SCOPE', 'cliente'),

    /*
    |--------------------------------------------------------------------------
    | Vigencia de tokens
    |--------------------------------------------------------------------------
    */

    'tokens' => [
        'access_minutes' => (int) env('OAUTH_ACCESS_TOKEN_MINUTES', 60 * 24),        // 1 día
        'refresh_days' => (int) env('OAUTH_REFRESH_TOKEN_DAYS', 30),                 // 30 días
        'personal_access_days' => (int) env('OAUTH_PERSONAL_ACCESS_DAYS', 90),       // 90 días
    ],

    /*
    |--------------------------------------------------------------------------
    | Reglas de tarjetas y sellos
    |--------------------------------------------------------------------------
    */

    'cards' => [
        /*
         | Configuración por defecto de una tarjeta nueva; el negocio puede
         | sobrescribirla en su perfil (PUT /api/business/card-settings).
         */
        'default_settings' => [
            'primary_color' => '#F59E0B',
            'secondary_color' => '#111827',
            'text_color' => '#FFFFFF',
            'stamp_icon' => 'star',
            'card_shape' => 'rounded',
            'welcome_message' => '¡Bienvenido a nuestro programa de fidelidad!',
        ],

        'default_required_stamps' => (int) env('CARD_DEFAULT_REQUIRED_STAMPS', 10),
        'min_required_stamps' => 1,
        'max_required_stamps' => 100,

        /*
         | Anti-fraude: segundos mínimos entre dos sellos de la misma tarjeta.
         */
        'stamp_throttle_seconds' => (int) env('CARD_STAMP_THROTTLE_SECONDS', 30),

        /*
         | Máximo de sellos que un negocio puede registrar a una tarjeta por día (0 = sin límite).
         */
        'max_stamps_per_day' => (int) env('CARD_MAX_STAMPS_PER_DAY', 0),

        /*
         | Al canjear una recompensa, la tarjeta reinicia su contador para empezar
         | un nuevo ciclo.
         */
        'reset_progress_on_redeem' => env('CARD_RESET_PROGRESS_ON_REDEEM', true),

        /*
         | Días de vigencia de los sellos (null = nunca expiran).
         */
        'stamps_lifetime_days' => env('CARD_STAMPS_LIFETIME_DAYS'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Archivos (logo, fondo y sello de las tarjetas)
    |--------------------------------------------------------------------------
    */

    'assets' => [
        'disk' => env('PUNTO_PLUS_ASSET_DISK', 'public'),
        'max_kilobytes' => (int) env('PUNTO_PLUS_ASSET_MAX_KB', 4096),
        'allowed_mimes' => ['image/jpeg', 'image/png', 'image/webp'],
        'allowed_extensions' => ['jpg', 'jpeg', 'png', 'webp'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Paginación
    |--------------------------------------------------------------------------
    */

    'per_page' => [
        'default' => 15,
        'max' => 100,
    ],
];
