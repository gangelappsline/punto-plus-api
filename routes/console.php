<?php

use Illuminate\Support\Facades\Schedule;

/*
|--------------------------------------------------------------------------
| Tareas programadas
|--------------------------------------------------------------------------
|
| `php artisan schedule:work` (o un cron cada minuto) ejecuta estas tareas.
|
*/

// Purga tokens revocados/caducados, códigos de autorización y device codes.
Schedule::command('passport:purge')->daily()->at('03:00');

// Cierra tarjetas caducadas y libera códigos de canje pendientes olvidados.
Schedule::command('model:prune', ['--model' => [\App\Models\Redemption::class]])->daily()->at('03:30');

// Marca como `expired` las tarjetas que ya no pueden sellar y reinicia los sellos
// vencidos (CARD_STAMPS_LIFETIME_DAYS / settings.stamps_lifetime_days).
Schedule::command('punto-plus:expire-cards')->daily()->at('04:00');
