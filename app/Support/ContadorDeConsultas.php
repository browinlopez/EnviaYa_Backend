<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * CUÁNTAS CONSULTAS LLEVA ESTA PETICIÓN.
 *
 * Existe por descarte. `Connection` expone `totalQueryDuration()` pero no un
 * contador, y la forma obvia de contarlas —`enableQueryLog()`— guarda la SQL
 * y los parámetros de CADA consulta en memoria hasta que alguien la vacíe. En
 * una petición normal eso son unos kilobytes y da igual; en un informe que
 * recorre miles de filas son megabytes, y bajo Octane la memoria del
 * trabajador no se recicla al terminar la petición. Encender el log de
 * consultas en producción para contar hasta quince es pagar un riesgo por un
 * número.
 *
 * Un oyente que incrementa un entero no guarda nada. Se registra en el `boot`
 * del proveedor y no en el middleware: el proveedor arranca una vez por
 * instancia de la aplicación —o sea una vez por trabajador bajo Octane—
 * mientras que el middleware corre una vez por petición, y registrarlo ahí
 * acumularía un oyente por cada una: a la milésima petición, cada consulta
 * llamaría a mil closures.
 *
 * Por eso el contador se REINICIA por petición en vez de crearse: el estado
 * sobrevive al final de la petición igual que la conexión, así que lo que
 * separa una medida de la siguiente es el reinicio, no el alcance.
 *
 * EL CENTINELA MIRA QUÉ DESPACHADOR, NO SI YA SE HIZO, y eso costó encontrarlo.
 *
 * La primera versión llevaba un `static bool $registrado` para no registrar
 * dos veces. Un booleano estático sobrevive a la aplicación, no solo a la
 * petición: en cuanto algo reconstruye el contenedor —cada prueba con
 * `RefreshDatabase`, y cualquier entorno que rearme los proveedores— el `boot`
 * nuevo veía «ya está hecho» y volvía sin registrar nada, mientras el oyente
 * que sí existía apuntaba a un despachador de eventos ya tirado. Resultado:
 * la PRIMERA petición del proceso contaba bien y todas las demás escribían
 * `consultas: 0`, un cero que parece «esta ruta no toca la base» y que no se
 * distingue de la verdad.
 *
 * Guardando el despachador contra el que se registró, el centinela dice lo que
 * de verdad importa —si ESTE oyente sigue enchufado a ESTE bus— y se vuelve a
 * registrar cuando hace falta. Bajo Octane el despachador vive lo que vive el
 * trabajador, así que sigue siendo un solo registro.
 */
class ContadorDeConsultas
{
    private static int $cuantas = 0;

    /** El despachador contra el que se registró el oyente, no un «ya está». */
    private static ?int $despachador = null;

    public static function registrar(): void
    {
        $bus = spl_object_id(app('events'));

        if (self::$despachador === $bus) {
            return;
        }

        self::$despachador = $bus;

        DB::listen(function (): void {
            self::$cuantas++;
        });
    }

    public static function reiniciar(): void
    {
        self::$cuantas = 0;
    }

    public static function cuantas(): int
    {
        return self::$cuantas;
    }
}
