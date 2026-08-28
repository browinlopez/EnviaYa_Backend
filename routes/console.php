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

/*
| Liquidaciones del día anterior, en borrador.
|
| A las 3 de la mañana: después de la copia de seguridad —para que el respaldo
| no incluya un corte a medias— y antes del resumen de las 7, para que quien lo
| abra ya vea el saldo del día.
|
| Ayer y no hoy: a esa hora el día de hoy sigue abierto, y liquidar un día a
| medias obligaría a un segundo corte por los pedidos de la tarde.
|
| El comando es idempotente: descarta los pedidos que ya estén en otro corte
| vivo, así que un reintento no duplica nada.
*/
Schedule::command('liquidaciones:diarias')
    ->dailyAt('03:00')
    ->timezone('America/Bogota')
    ->withoutOverlapping();

/*
| Poda de la auditoria.
|
| `audits` guarda una fila por cada cambio de cada modelo auditable, con el
| antes y el despues en JSON: 1,7 kB por fila medidos en esta base. Sin poda
| crece indefinidamente, y a mil cambios al dia son unos 600 MB al anio.
|
| Mensual y no diaria: se conserva un horizonte de dos anios, asi que lo que
| sobra en un dia cualquiera son las filas de un solo dia de hace dos anios. No
| hay prisa, y una tarea que corre poco molesta poco.
|
| El domingo 1 a las 4 de la maniana: despues de la copia de seguridad, para
| que lo podado quede respaldado al menos una vez mas, y en el dia de menos
| pedidos.
*/
Schedule::command('auditoria:podar')
    ->monthlyOn(1, '04:00')
    ->timezone('America/Bogota')
    ->withoutOverlapping();
