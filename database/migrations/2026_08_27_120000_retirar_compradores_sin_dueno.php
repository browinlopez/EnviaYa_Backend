<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * FICHAS DE COMPRADOR QUE SE QUEDARON SIN NADIE.
 *
 * `buyer.user_id` tiene su foránea a `user` en **SET NULL**, mientras que las
 * de `owner` y `domiciliary` están en **CASCADE**. Al borrar una cuenta, su
 * ficha de comprador no se iba con ella: quedaba con `user_id = NULL` y sin
 * forma de llegar a ella desde ningún sitio.
 *
 * En la base local había cinco. Nada colgaba de ellas.
 *
 * NO SE CAMBIA LA FORÁNEA, y es deliberado: `orderssales.buyer_id` también es
 * SET NULL, así que conservar la fila —vacía de identidad— es lo que evita que
 * un pedido pasado se quede sin saber a qué comprador fue, que es justo lo que
 * necesita un comprobante. Lo que no tiene sentido es conservarla cuando no
 * hay nada que conservar.
 *
 * Se limpia el residuo de una vez, y de aquí en adelante lo evita
 * `ProfileController::destroy`, que es el único sitio del proyecto donde una
 * persona borra su propia cuenta.
 */
return new class extends Migration
{
    public function up(): void
    {
        $huerfanas = DB::table('buyer')
            ->whereNull('user_id')
            // Con historial no se toca: el pedido tiene que poder seguir
            // apuntando a su comprador aunque la persona ya no exista.
            ->whereNotExists(fn ($q) => $q->from('orderssales')
                ->whereColumn('orderssales.buyer_id', 'buyer.buyer_id'))
            ->whereNotExists(fn ($q) => $q->from('buyer_complex')
                ->whereColumn('buyer_complex.buyer_id', 'buyer.buyer_id'))
            ->pluck('buyer_id');

        if ($huerfanas->isEmpty()) {
            return;
        }

        DB::table('buyer')->whereIn('buyer_id', $huerfanas)->delete();
    }

    /**
     * No se puede deshacer, y no se finge que sí.
     *
     * Devolver estas filas exigiría inventarles el usuario que ya no existe.
     * Lo que se borró era exactamente lo que no tenía nada detrás.
     */
    public function down(): void
    {
        //
    }
};
