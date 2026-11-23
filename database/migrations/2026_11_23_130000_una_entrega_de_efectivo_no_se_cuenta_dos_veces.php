<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * EL MISMO BILLETE, CONTADO DOS VECES.
 *
 * `POST /negocio/efectivo/{id}/entregas` registra que un domiciliario le
 * entregó plata a la tienda en el mostrador. No era idempotente, y eso con
 * una red de barrio es un agujero:
 *
 *   1. el tendero toca «Recibir $5.000»;
 *   2. la petición sale y el servidor la ejecuta —baja el saldo, crea el
 *      depósito— pero la respuesta no llega porque se cayó la señal;
 *   3. la app dice «No se pudo guardar, inténtalo de nuevo», que es lo que
 *      tiene que decir cuando no sabe;
 *   4. el tendero vuelve a tocar «Recibir $5.000», porque es lo natural;
 *   5. quedan DOS depósitos de $5.000 por un solo billete de $5.000.
 *
 * Medido contra el servidor local: con el cliente abortando a los 0,3 s el
 * saldo pasó de 49.600 a 44.600 con la app creyendo que había fallado, y al
 * reintentar quedó en 39.600. Al domiciliario se le abonaron $10.000 por
 * $5.000 reales, y a favor suyo.
 *
 * LA CLAVE LA PONE QUIEN PULSA, no el servidor. El servidor no puede
 * distinguir «me entregó otros $5.000» de «es el mismo de antes»: dos
 * entregas iguales seguidas pasan de verdad. Quien sí lo sabe es la app, que
 * genera una clave al abrir el formulario y la repite en cada reintento DE
 * ESE intento; si el tendero vuelve a abrirlo, es otra clave y otra entrega.
 *
 * `unique` y no una comprobación en PHP: dos reintentos a la vez --el toque
 * doble sobre un botón que no respondía-- leerían los dos que no existe.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cash_deposits', function (Blueprint $table) {
            $table->uuid('idempotency_key')->nullable()->after('reference');
            $table->unique('idempotency_key');
        });
    }

    public function down(): void
    {
        Schema::table('cash_deposits', function (Blueprint $table) {
            $table->dropUnique(['idempotency_key']);
            $table->dropColumn('idempotency_key');
        });
    }
};
