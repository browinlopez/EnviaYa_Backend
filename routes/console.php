<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
|--------------------------------------------------------------------------
| Tareas programadas
|--------------------------------------------------------------------------
|
| Para que esto corra en el servidor hace falta UNA línea en el cron:
|
|     * * * * * cd /ruta/del/proyecto && php artisan schedule:run >> /dev/null 2>&1
|
| Sin ella nada de acá se ejecuta, y el resumen diario queda como un comando
| que hay que acordarse de lanzar — es decir, como no tenerlo.
*/

// A las 7 de la mañana, hora de Colombia: antes de que arranque la operación,
// para que lo urgente se atienda el mismo día y no al siguiente.
Schedule::command('resumen:diario')
    ->dailyAt('07:00')
    ->timezone('America/Bogota')
    // Sin solapamiento: si un envío se atasca, el del día siguiente no arranca
    // encima y manda todo dos veces.
    ->withoutOverlapping();

/*
| Copias de seguridad.
|
| Limpiar antes de copiar, y no al revés: si el disco está lleno, la copia
| nueva falla y encima se queda sin espacio para el intento siguiente.
|
| `monitor:backups` es lo que convierte esto en un respaldo de verdad: revisa
| que la última copia exista y no esté vacía. Sin esa comprobación, un fallo
| silencioso en la copia se descubre el día que hay que restaurar.
*/
Schedule::command('backup:clean')
    ->dailyAt('02:00')
    ->timezone('America/Bogota');

Schedule::command('backup:run')
    ->dailyAt('02:30')
    ->timezone('America/Bogota')
    ->withoutOverlapping();

Schedule::command('backup:monitor')
    ->dailyAt('08:00')
    ->timezone('America/Bogota');
