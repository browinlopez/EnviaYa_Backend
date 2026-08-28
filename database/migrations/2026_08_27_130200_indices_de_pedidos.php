<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * LOS ÍNDICES QUE `orderssales` NO TENÍA, Y SON LOS QUE SE CONSULTAN.
 *
 * La tabla tenía índice en sus seis foráneas —buyer, business, domiciliary,
 * methods, forms, coupon— y **ninguno en `state` ni en `created_at`**, que es
 * por donde filtra la plataforma entera:
 *
 *  · el tablero del tendero:  `busines_id = ? AND state IN (…)`, y se refresca
 *    solo **cada 20 segundos** mientras la pestaña está a la vista;
 *  · los reportes:            `created_at BETWEEN ? AND ?`;
 *  · la liquidación diaria:   `state = 4` sobre el día anterior;
 *  · los indicadores del panel y el aviso de pedidos estancados.
 *
 * Con 114 pedidos no se nota nada: MySQL recorre la tabla entera en un
 * suspiro. A los cien mil, cada una de esas consultas es un escaneo completo, y
 * el tablero lo repite cada veinte segundos por cada tendero conectado. Es el
 * tipo de problema que no avisa: sencillamente un día el panel va lento.
 *
 * DOS ÍNDICES Y NO CUATRO. El orden de las columnas no es indiferente:
 *
 *  · `(busines_id, state)` sirve al tablero, y también a cualquier consulta
 *    que filtre sólo por negocio —un índice compuesto se puede usar por su
 *    prefijo izquierdo—. Por eso no hace falta uno suelto de `busines_id`; el
 *    que ya existe por la foránea se queda porque MySQL lo necesita para
 *    comprobarla.
 *
 *  · `(state, created_at)` sirve a la liquidación y a los reportes por
 *    periodo, que siempre acotan por estado. Al revés —`(created_at, state)`—
 *    obligaría a leer todo el rango de fechas para luego descartar por estado.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orderssales', function (Blueprint $table) {
            $table->index(['busines_id', 'state'], 'ix_pedidos_negocio_estado');
            $table->index(['state', 'created_at'], 'ix_pedidos_estado_fecha');
        });
    }

    public function down(): void
    {
        Schema::table('orderssales', function (Blueprint $table) {
            $table->dropIndex('ix_pedidos_estado_fecha');
            $table->dropIndex('ix_pedidos_negocio_estado');
        });
    }
};
