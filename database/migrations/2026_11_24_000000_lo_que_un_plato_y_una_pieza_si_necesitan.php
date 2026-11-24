<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ==========================================================================
 * LOS CAMPOS QUE LE FALTABAN A UN PLATO Y A UNA PIEZA DE REPUESTO.
 *
 * `minutos_preparacion` ES EL MÁS IMPORTANTE DE TODO EL REPARTO DE CAMPOS.
 *
 * La plataforma entera promete tiempos de entrega —el domiciliario ve
 * «promised_minutes», el comprador ve «llega en 30»— y no había dónde decir
 * que una bandeja paisa tarda treinta y cinco minutos en salir de la cocina y
 * una gaseosa ninguno. Sin ese dato la promesa es una adivinanza: se suma el
 * trayecto y se ignora lo que de verdad tarda el negocio.
 *
 * `acompanamientos` es lo que HACE que un corrientazo sea un corrientazo:
 * «sopa, arroz, principio, carne y jugo». Hoy eso había que meterlo dentro
 * del nombre del plato o dejarlo sin decir.
 *
 * `es_vegetariano` no es lo mismo que `is_vegan`, que ya existía, y en
 * Colombia se pide mucho más.
 *
 * --------------------------------------------------------------------------
 * Y EN REPUESTOS, LAS DOS PREGUNTAS DEL MOSTRADOR.
 *
 * `posicion` —delantero, trasero, izquierdo, derecho— es el error clásico al
 * comprar frenos, amortiguadores o faros: se lleva el del otro lado.
 *
 * `condicion` —original, genérico o remanufacturado— es lo primero que
 * pregunta cualquiera y cambia el precio al doble.
 *
 * --------------------------------------------------------------------------
 * LAS COLUMNAS VIEJAS NO SE BORRAN.
 *
 * `restaurant_products.food_type` y `car_parts_products.brand`, `.model` y
 * `.year` salen del esquema público pero se quedan en la tabla, igual que se
 * hizo con el contenido y el envase. Borrar es una migración destructiva
 * sobre tablas que todavía no se han terminado de vaciar.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('restaurant_products', function (Blueprint $tabla) {
            $tabla->unsignedSmallInteger('minutos_preparacion')->nullable()->after('portion_size');
            $tabla->string('acompanamientos', 255)->nullable()->after('minutos_preparacion');
            $tabla->boolean('es_vegetariano')->default(false)->after('acompanamientos');
        });

        Schema::table('car_parts_products', function (Blueprint $tabla) {
            $tabla->string('posicion', 40)->nullable()->after('compatibility');
            $tabla->string('condicion', 30)->nullable()->after('posicion');
        });
    }

    public function down(): void
    {
        Schema::table('restaurant_products', function (Blueprint $tabla) {
            $tabla->dropColumn(['minutos_preparacion', 'acompanamientos', 'es_vegetariano']);
        });

        Schema::table('car_parts_products', function (Blueprint $tabla) {
            $tabla->dropColumn(['posicion', 'condicion']);
        });
    }
};
