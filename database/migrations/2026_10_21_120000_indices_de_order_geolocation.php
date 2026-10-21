<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * DOS ÍNDICES PARA LA TABLA QUE ESTÁ A PUNTO DE CRECER.
 *
 * `order_geolocation` tiene hoy trece filas, y con trece filas cualquier
 * consulta es rápida porque recorrer la tabla entera cuesta nada. Eso se
 * acaba con el reporte durante todo el turno: el gestor de tareas despierta
 * cada veinte segundos, y una moto en movimiento recorre los veinticinco
 * metros del filtro en tres, así que mientras se rueda la regla no descarta
 * casi nada. Sale una fila cada veinte segundos por domiciliario en reparto
 * —unas ciento ochenta por hora—, y en noventa días con veinte motos son
 * millones.
 *
 * LOS ÍNDICES QUE HAY NO SIRVEN PARA LO QUE SE PREGUNTA. Están
 * `domiciliary_id` solo, `orderSales_id` solo y el par
 * `(orderSales_id, created_at)` —ese es para dibujar el recorrido de un
 * pedido—. Pero las dos consultas que van a doler no son ninguna de esas:
 *
 *  · `WHERE created_at < corte`, que es la poda de los noventa días. Sin
 *    índice por fecha recorre la tabla COMPLETA cada noche para encontrar lo
 *    que hay que borrar, y la tabla es justamente lo que está creciendo.
 *
 *  · `WHERE domiciliary_id = ? ORDER BY created_at DESC LIMIT 1`, que es la
 *    última posición de una moto: la torre del tendero cada quince segundos y
 *    el mapa del comprador siguiendo su pedido. Con el índice por
 *    `domiciliary_id` a secas, la base encuentra las filas de esa moto pero
 *    tiene que ordenarlas todas para saber cuál es la última. Con el par, la
 *    última fila ES el final del índice: la encuentra sin ordenar nada.
 *
 * NO SE QUITA EL ÍNDICE VIEJO de `domiciliary_id`. El par lo deja redundante
 * —empieza por la misma columna—, pero esa clave ajena se declaró con ese
 * nombre y soltarle el índice por debajo es la clase de migración que falla
 * en producción y no en pruebas. Un índice de más en una tabla que se escribe
 * cada veinte segundos cuesta mucho menos que eso.
 */
return new class extends Migration
{
    /** Los que ya están, en minúsculas, para no intentar crearlos dos veces. */
    private function existentes(): array
    {
        return collect(Schema::getIndexes('order_geolocation'))
            ->pluck('name')
            ->filter()
            ->map(fn ($nombre) => strtolower((string) $nombre))
            ->all();
    }

    public function up(): void
    {
        $existentes = $this->existentes();

        Schema::table('order_geolocation', function (Blueprint $table) use ($existentes) {
            // Para la poda: encontrar lo anterior al corte sin leer el resto.
            if (!in_array('idx_geolocation_fecha', $existentes, true)) {
                $table->index('created_at', 'idx_geolocation_fecha');
            }

            // Para la última posición de cada moto, que es la pregunta caliente.
            if (!in_array('idx_geolocation_domi_fecha', $existentes, true)) {
                $table->index(['domiciliary_id', 'created_at'], 'idx_geolocation_domi_fecha');
            }
        });
    }

    public function down(): void
    {
        $existentes = $this->existentes();

        Schema::table('order_geolocation', function (Blueprint $table) use ($existentes) {
            if (in_array('idx_geolocation_domi_fecha', $existentes, true)) {
                $table->dropIndex('idx_geolocation_domi_fecha');
            }

            if (in_array('idx_geolocation_fecha', $existentes, true)) {
                $table->dropIndex('idx_geolocation_fecha');
            }
        });
    }
};
