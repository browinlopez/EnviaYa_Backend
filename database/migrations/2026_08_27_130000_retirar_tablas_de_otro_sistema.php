<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * SIETE TABLAS QUE NO SON DE ESTE PROYECTO.
 *
 * Estaban dentro de la base de datos de VeciPa'Ya sin que ninguna migración las
 * creara ni ninguna línea de código las mencionara. Son de otra aplicación —de
 * crédito o fiado, a juzgar por `cupo_total`, `fuente_ingreso`,
 * `camara_comercio` y `certificado_ingresos_contador`— que en algún momento
 * compartió base con ésta.
 *
 * Se distinguen a simple vista: claves `idPersonas`, `idTendero`, nombres en
 * español, y colaciones distintas al resto (`utf8mb3_general_ci`,
 * `utf8mb4_0900_ai_ci`, `utf8mb4_general_ci` frente al `utf8mb4_unicode_ci` de
 * las 96 propias).
 *
 * NO ES ORDEN, ES PROTECCIÓN DE DATOS. No estaban vacías:
 *
 *   personas  40 filas — nombre, edad, dirección, barrio y número de documento
 *                        de cuarenta personas identificadas
 *   tendero   25 filas — cédula, cámara de comercio, RUT
 *   usuario   13 filas — con `password varchar(50)` y valores de 9 caracteres:
 *                        contraseñas EN TEXTO PLANO
 *
 * Todo eso vivía en la misma base que la plataforma, alcanzable con las mismas
 * credenciales, y entraba en cada copia de seguridad de las 2:30 de la mañana.
 *
 * ANTES DE BORRAR SE VOLCARON a `_respaldo-privado/sistema-anterior-2026-08-27.sql`,
 * fuera de todos los repositorios —`EnviaYa_Backend` es público—. El `LEEME.md`
 * de esa carpeta explica qué hacer con el archivo.
 *
 * Ninguna tabla del proyecto las referencia, así que no hay foráneas que caer y
 * el orden de borrado da igual.
 */
return new class extends Migration
{
    private const AJENAS = [
        'movimientouser',
        'movimiento',
        'undeco',
        'usuario',
        'tendero',
        'personas',
        'administrador',
    ];

    public function up(): void
    {
        /*
         * Se comprueba que sigan sin estar referenciadas ANTES de borrar.
         *
         * Es barato y evita el caso en que alguien haya atado algo a ellas
         * entre la auditoría y el despliegue: mejor que la migración falle a
         * que se lleve por delante una relación que sí existía.
         */
        if (DB::getDriverName() !== 'mysql') {
            /*
             * En SQLite —donde corren las pruebas— estas tablas no existen
             * nunca: ninguna migración las crea. Y `information_schema` no
             * existe allí, así que la comprobación de abajo reventaría la suite
             * entera. `dropIfExists` se encarga del resto.
             */
            foreach (self::AJENAS as $tabla) {
                Schema::dropIfExists($tabla);
            }

            return;
        }

        $referencias = DB::select(
            'SELECT TABLE_NAME t, COLUMN_NAME c, REFERENCED_TABLE_NAME r
               FROM information_schema.KEY_COLUMN_USAGE
              WHERE CONSTRAINT_SCHEMA = DATABASE()
                AND REFERENCED_TABLE_NAME IN (' . implode(',', array_fill(0, count(self::AJENAS), '?')) . ')',
            self::AJENAS,
        );

        if ($referencias !== []) {
            $lista = implode(', ', array_map(fn ($x) => "{$x->t}.{$x->c} → {$x->r}", $referencias));

            throw new RuntimeException(
                "No se retiran: algo apunta a ellas ({$lista}). Revísalo antes."
            );
        }

        foreach (self::AJENAS as $tabla) {
            Schema::dropIfExists($tabla);
        }
    }

    /**
     * No se pueden devolver desde aquí, y no se finge que sí.
     *
     * Volver a crearlas exigiría llevar su estructura y sus datos dentro de
     * esta migración — es decir, meter en un repositorio público exactamente lo
     * que se está sacando de la base. El volcado está en `_respaldo-privado/`;
     * restaurarlo, si alguna vez hiciera falta, es correr ese `.sql` a mano.
     */
    public function down(): void
    {
        //
    }
};
