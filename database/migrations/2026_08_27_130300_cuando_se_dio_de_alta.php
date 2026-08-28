<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * CUÁNDO SE DIO DE ALTA CADA COSA.
 *
 * Cuarenta y tres tablas no tienen `created_at`, y entre ellas están las
 * maestras: `user`, `business`, `products`, `residential_complexes`. La
 * consecuencia es concreta y se nota al abrir Reportes:
 *
 *   «¿cuántas tiendas afiliamos en julio?»
 *   «¿cuánto creció el padrón este mes?»
 *   «¿desde cuándo es cliente esta persona?»
 *
 * no se pueden responder desde la base. No es que la consulta salga lenta: es
 * que el dato no existe. Para una plataforma con un módulo de reportes, eso es
 * una limitación de fondo.
 *
 * LAS FILAS DE ANTES SE QUEDAN EN NULL, a propósito. Rellenarlas con la fecha
 * de la migración sería inventarse que las siete tiendas se afiliaron todas el
 * mismo día de agosto, y un dato inventado en una columna de fecha es peor que
 * no tenerlo: se usa sin sospechar. `NULL` significa «esto es anterior a que
 * empezáramos a anotarlo», que es la verdad.
 *
 * EL VALOR LO PONE LA APLICACIÓN, NO LA BASE. Y esto costó una vuelta.
 *
 * La primera versión ponía `DEFAULT CURRENT_TIMESTAMP` en las columnas, para
 * que se rellenaran vinieran por Eloquent o por el constructor de consultas
 * —el proyecto usa los dos—. Parecía lo robusto, y era lo contrario:
 * `CURRENT_TIMESTAMP` es el reloj del SERVIDOR, y en un VPS ese reloj está en
 * UTC mientras PHP trabaja en `America/Bogota`. Dos filas creadas en el mismo
 * instante habrían quedado con cinco horas de diferencia en la misma columna.
 *
 * Se podría haber atado la zona de la conexión, pero eso reinterpreta TODO lo
 * ya guardado: MySQL convierte los `timestamp` al leerlos, así que fijar la
 * sesión en una base que venía en otra zona corre el pasado entero.
 *
 * Así que no dependen del servidor: las escribe PHP, siempre, y los ocho
 * sitios que insertan con `DB::table()` en estas cuatro tablas lo hacen
 * explícito. Si mañana aparece un noveno y se olvida, la columna queda en
 * `NULL` —«no se anotó»— y no con una fecha corrida cinco horas, que es un
 * error que nadie ve.
 *
 * Las columnas quedan NULABLES y SIN DEFECTO por eso mismo.
 */
return new class extends Migration
{
    private const TABLAS = ['user', 'business', 'products', 'residential_complexes'];

    public function up(): void
    {
        foreach (self::TABLAS as $tabla) {
            Schema::table($tabla, function (Blueprint $t) use ($tabla) {
                if (!Schema::hasColumn($tabla, 'created_at')) {
                    $t->timestamp('created_at')->nullable();
                }
                if (!Schema::hasColumn($tabla, 'updated_at')) {
                    $t->timestamp('updated_at')->nullable();
                }
            });
        }

    }

    public function down(): void
    {
        foreach (self::TABLAS as $tabla) {
            Schema::table($tabla, function (Blueprint $t) {
                $t->dropColumn(['created_at', 'updated_at']);
            });
        }
    }
};
