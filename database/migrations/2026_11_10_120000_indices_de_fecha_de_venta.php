<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * `orderssales.sale_date`: LA COLUMNA POR LA QUE ACOTAN TODOS LOS INFORMES, Y
 * NO TENÍA NINGÚN ÍNDICE.
 *
 * `GeneradorDeReportes::pedidosDelPeriodo()` abre con
 * `whereBetween('o.sale_date', ...)`, y de ahí salen el informe de ventas, el
 * operacional y el de domiciliarios. `orderssales` tenía
 * `(busines_id, state)` y `(state, created_at)`: ninguno de los dos sirve
 * para un rango sobre `sale_date`.
 *
 * ANTES, CON `EXPLAIN` SOBRE MYSQL 8.0.42:
 *
 *   select count(*) from orderssales o
 *   where o.sale_date between '2026-10-06' and '2026-10-13'
 *   -->  type=ALL   possible_keys=NINGUNA
 *
 * `possible_keys` vacío es la parte que importa: no es que el optimizador
 * prefiriera el escaneo, es que **no existía ningún índice capaz de servir
 * esa consulta**. Eso es cierto a cualquier volumen.
 *
 * DESPUÉS, con los dos índices de abajo, sobre la misma base:
 *
 *   una semana, toda la plataforma   type=range  ix_pedidos_fecha_venta
 *                                    examina 49, devuelve 49
 *   una semana de UNA tienda         type=range  ix_pedidos_negocio_fecha_venta
 *                                    examina 49, devuelve 49
 *
 * Examinar exactamente lo que se devuelve es el objetivo: ni una fila de más.
 *
 * DOS ÍNDICES Y NO UNO, porque son dos patrones de acceso y cada medición
 * eligió el suyo:
 *
 *   (sale_date)               el informe de la plataforma, sin filtro de
 *                             tienda: el rango es todo lo que hay
 *   (busines_id, sale_date)   el del tendero: igualdad y DESPUÉS rango, que
 *                             es el orden que permite buscar por el índice en
 *                             vez de filtrar fila a fila. Al revés
 *                             —(sale_date, busines_id)— el `busines_id` ya no
 *                             serviría para buscar
 *
 * El segundo pesa más desde hoy: `/negocio/domiciliarios/rendimiento` acaba
 * de abrirse al panel y a la app del tendero, y pasa por el mismo camino.
 *
 * EL ERROR QUE COSTÓ LA PRIMERA MEDICIÓN, que es justo contra lo que avisa
 * §24 —«datos representativos de tamaño Y DISTRIBUCIÓN»—. Se midió primero
 * con un rango de un mes: octubre tiene 277 de los 549 pedidos de la base de
 * desarrollo, o sea **media tabla**. Con eso el escaneo completo es el plan
 * CORRECTO, ningún índice se usa, y la medición no decía nada de los
 * índices: decía que la consulta de prueba estaba mal elegida. Con una
 * ventana de una semana —49 filas, que es la forma de una consulta de
 * verdad— los dos índices entran solos.
 *
 * LO QUE SIGUE SIN MEDIRSE: cuánto baja la latencia en producción. 549
 * pedidos y 7 tiendas no predicen el plan con cientos de miles y cientos de
 * tiendas, y no hay privilegios locales para montar una base con volumen. Lo
 * que sí está medido es que el índice se usa y que no examina de más.
 *
 * COSTE: dos B-tree más en cada escritura de `orderssales`. Es una tabla que
 * se escribe unos cientos de veces al día y se lee en cada informe, así que
 * el cambio va a favor. Si deja de estarlo, se revierte con `down()`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orderssales', function (Blueprint $table) {
            $table->index('sale_date', 'ix_pedidos_fecha_venta');
            $table->index(['busines_id', 'sale_date'], 'ix_pedidos_negocio_fecha_venta');
        });
    }

    public function down(): void
    {
        Schema::table('orderssales', function (Blueprint $table) {
            $table->dropIndex('ix_pedidos_fecha_venta');
            $table->dropIndex('ix_pedidos_negocio_fecha_venta');
        });
    }
};
