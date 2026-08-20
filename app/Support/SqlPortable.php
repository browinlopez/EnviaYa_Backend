<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * EXPRESIONES SQL QUE FUNCIONAN EN LOS DOS MOTORES
 *
 * La aplicación corre sobre MySQL y las pruebas sobre SQLite en memoria. Casi
 * todo el código usa el constructor de consultas y eso da igual, pero las
 * funciones de fecha no se llaman igual en los dos motores: una consulta con
 * `DATE_FORMAT` funciona en producción y revienta en las pruebas.
 *
 * El efecto es peor de lo que parece: no es que la prueba falle, es que ESA
 * parte del código se queda sin prueba posible y nadie se da cuenta. Fue lo que
 * pasó con los libros de Excel de los reportes, que estuvieron sin una sola
 * prueba mientras usaban `DATE_FORMAT`.
 */
class SqlPortable
{
    /** "2026-08" a partir de una columna de fecha. */
    public static function anioMes(string $columna): string
    {
        return self::esMysql()
            ? "DATE_FORMAT({$columna},'%Y-%m')"
            : "strftime('%Y-%m', {$columna})";
    }

    /** Minutos entre dos instantes. */
    public static function minutosEntre(string $desde, string $hasta): string
    {
        return self::esMysql()
            ? "TIMESTAMPDIFF(MINUTE, {$desde}, {$hasta})"
            : "(julianday({$hasta}) - julianday({$desde})) * 1440";
    }

    private static function esMysql(): bool
    {
        return in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true);
    }
}
