<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * LA RUTA DEJA DE SER UNA OPTIMIZACIÓN Y PASA A SER UN DATO DEL PEDIDO.
 *
 * El camino de la tienda a la casa es el mismo mientras el pedido viva, así
 * que se traza UNA vez y se guarda acá. A partir de entonces el comprador, el
 * domiciliario, el panel y la torre de control dibujan la misma línea sin que
 * nadie vuelva a llamar —ni a pagar— a Google.
 *
 * La caché por tienda y dirección que ya existe sigue sirviendo y no se toca:
 * es la que evita pagar el primer trazo de cada pedido nuevo al mismo
 * edificio. Esto es el suelo de abajo — una llamada por pedido, y ninguna
 * más, ni aunque se borre la caché.
 *
 * `route_polyline` es TEXT porque una polilínea codificada de un reparto de
 * ciudad ronda el kilobyte y puede pasarse de los 255 de un `string`. Se
 * guarda CODIFICADA, tal como la da Google: en coordenadas serían decenas de
 * miles de números.
 *
 * Sin valor por defecto y nullable: los quince mil pedidos que ya existen no
 * tienen ruta trazada, y eso no es un dato faltante que haya que rellenar —es
 * que se entregaron antes de que esto existiera—. `route_traced_at` distingue
 * «nunca se intentó» de «se intentó y Google no la dio».
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orderssales', function (Blueprint $table) {
            $table->text('route_polyline')->nullable()->after('promised_minutes');
            $table->timestamp('route_traced_at')->nullable()->after('route_polyline');
        });
    }

    public function down(): void
    {
        Schema::table('orderssales', function (Blueprint $table) {
            $table->dropColumn(['route_polyline', 'route_traced_at']);
        });
    }
};
