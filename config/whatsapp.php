<?php

/*
|--------------------------------------------------------------------------
| WhatsApp Cloud API (Meta)
|--------------------------------------------------------------------------
|
| Envío de mensajes desde la API con WhatsApp Cloud API (Meta) y recepción de
| mensajes/estados por webhook. Las credenciales se obtienen en el panel de
| Meta for Developers; el paso a paso está en `docs/whatsapp.md`.
|
*/

return [

    /*
    |--------------------------------------------------------------------------
    | Interruptor general
    |--------------------------------------------------------------------------
    |
    | Si es false, los endpoints de envío responden 503 sin llamar a Meta.
    | Útil para apagar la integración sin borrar las credenciales.
    |
    */

    'enabled' => (bool) env('WHATSAPP_ENABLED', false),

    /*
    |--------------------------------------------------------------------------
    | Credenciales de la Cloud API
    |--------------------------------------------------------------------------
    |
    | - phone_number_id: id del número emisor (WhatsApp Manager → API setup).
    | - business_account_id: id de la WABA (WhatsApp Business Account).
    | - access_token: token permanente de un usuario de sistema.
    | - app_secret: "App secret" de la app de Meta (firma de los webhooks).
    | - webhook_verify_token: cadena que tú eliges y repites en el panel de Meta.
    |
    */

    'graph_url' => env('WHATSAPP_GRAPH_URL', 'https://graph.facebook.com'),
    'api_version' => env('WHATSAPP_API_VERSION', 'v23.0'),
    'phone_number_id' => env('WHATSAPP_PHONE_NUMBER_ID'),
    'business_account_id' => env('WHATSAPP_BUSINESS_ACCOUNT_ID'),
    'access_token' => env('WHATSAPP_ACCESS_TOKEN'),
    'app_secret' => env('WHATSAPP_APP_SECRET'),
    'webhook_verify_token' => env('WHATSAPP_WEBHOOK_VERIFY_TOKEN'),

    /*
    |--------------------------------------------------------------------------
    | HTTP
    |--------------------------------------------------------------------------
    |
    | Sólo se reintenta ante errores de conexión: un 4xx de Meta (token,
    | plantilla sin aprobar, número fuera de la lista de pruebas) no se
    | arregla repitiendo la petición.
    |
    */

    'timeout_seconds' => (int) env('WHATSAPP_TIMEOUT_SECONDS', 15),
    'connect_timeout_seconds' => (int) env('WHATSAPP_CONNECT_TIMEOUT_SECONDS', 5),
    'retry_times' => (int) env('WHATSAPP_RETRY_TIMES', 2),
    'retry_sleep_ms' => (int) env('WHATSAPP_RETRY_SLEEP_MS', 300),

    /*
    |--------------------------------------------------------------------------
    | Números y plantillas
    |--------------------------------------------------------------------------
    |
    | `default_country_code` se usa para completar los teléfonos de 10 dígitos
    | que llegan sin lada (52 = México). Para cualquier otro formato hay que
    | enviar el número en E.164 (p. ej. +5215512345678).
    |
    */

    'default_country_code' => env('WHATSAPP_DEFAULT_COUNTRY_CODE', '52'),

    'templates' => [
        'language' => env('WHATSAPP_TEMPLATE_LANGUAGE', 'es_MX'),
    ],

];
