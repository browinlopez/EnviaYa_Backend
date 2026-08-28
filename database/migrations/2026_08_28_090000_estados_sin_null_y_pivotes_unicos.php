<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * DOS COSAS QUE HOY NO FALLAN Y NO PUEDEN SEGUIR ASÍ.
 *
 * ── 1 · `state` no puede admitir NULL ───────────────────────────────────
 *
 * En las tablas de este año la columna es `NOT NULL DEFAULT 1`. En el núcleo
 * heredado admite NULL y no tiene defecto. La diferencia importa porque el
 * código pregunta siempre `where('state', 1)`:
 *
 *   · un usuario con `state = NULL` no aparece en el padrón, y tampoco en el
 *     listado de inactivos — desaparece de las dos;
 *   · un producto con NULL deja de venderse sin que nadie lo haya retirado;
 *   · un pago con NULL no cuenta en ningún corte.
 *
 * Y no daría error en ningún sitio. Hoy no hay ni una fila así —se comprobó
 * tabla por tabla—, así que el momento de cerrarlo es ahora, mientras el
 * cambio es gratis.
 *
 * `DEFAULT 1` en todas: es el único valor que existe hoy en las columnas de
 * activo/inactivo, y en `orderssales` el 1 es «sin aceptar», que es el estado
 * con el que nace un pedido.
 *
 * ── 2 · Cuatro pivotes admiten la misma pareja dos veces ────────────────
 *
 * `buyer_complex`, `chat_participants`, `orders_promotions` y
 * `payment_method_forms` no tienen índice único sobre su pareja de claves. Un
 * residente vinculado dos veces al mismo conjunto se cuenta dos veces en el
 * resumen del panel de aliados, y un participante repetido en un chat recibe
 * cada mensaje dos veces.
 *
 * Cero duplicados hoy, también comprobado. El único los hace imposibles y,
 * de paso, sirve de índice para las consultas por la primera columna.
 */
return new class extends Migration
{
    /** tabla => valor por defecto del estado. */
    private const ESTADOS = [
        'business_reviews'    => 1,
        'buyer'               => 1,
        'category'            => 1,
        'domiciliary'         => 1,
        'domiciliary_reviews' => 1,
        'notifications'       => 1,
        'order_geolocation'   => 1,
        'orderssales'         => 1,
        'owner'               => 1,
        'payment_forms'       => 1,
        'payment_methods'     => 1,
        'payments'            => 1,
        'products'            => 1,
        'user'                => 1,
        'user_address'        => 1,
    ];

    /** tabla => la pareja que no puede repetirse. */
    private const PAREJAS = [
        'buyer_complex'        => ['buyer_id', 'complex_id'],
        'chat_participants'    => ['chat_id', 'user_id'],
        'orders_promotions'    => ['orderSales_id', 'promotion_id'],
        'payment_method_forms' => ['methods_id', 'forms_id'],
    ];

    public function up(): void
    {
        /* ------------------------------------------------------------------
           1 · Estados
           ------------------------------------------------------------------ */
        foreach (self::ESTADOS as $tabla => $defecto) {
            // Por si acaso: si apareciera una fila con NULL entre la auditoría
            // y el despliegue, se le pone el valor en vez de reventar.
            DB::table($tabla)->whereNull('state')->update(['state' => $defecto]);

            if (DB::getDriverName() === 'mysql') {
                DB::statement("ALTER TABLE `{$tabla}` MODIFY `state` TINYINT NOT NULL DEFAULT {$defecto}");
            }
        }

        /* ------------------------------------------------------------------
           2 · Pivotes
           ------------------------------------------------------------------ */
        foreach (self::PAREJAS as $tabla => $columnas) {
            $this->retirarDuplicados($tabla, $columnas);

            Schema::table($tabla, function (Blueprint $t) use ($tabla, $columnas) {
                $t->unique($columnas, "uq_{$tabla}");
            });
        }
    }

    public function down(): void
    {
        foreach (array_keys(self::PAREJAS) as $tabla) {
            Schema::table($tabla, fn (Blueprint $t) => $t->dropUnique("uq_{$tabla}"));
        }

        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        foreach (array_keys(self::ESTADOS) as $tabla) {
            DB::statement("ALTER TABLE `{$tabla}` MODIFY `state` TINYINT NULL");
        }
    }

    /**
     * Deja una sola fila de cada pareja repetida, la más antigua.
     *
     * No hay ninguna hoy. Va igual porque una migración que revienta a mitad
     * en producción es peor que una que se ocupa del caso: si alguien duplica
     * un vínculo entre esto y el despliegue, el índice único no se podría
     * crear y el despliegue quedaría a medias.
     *
     * La más antigua y no la más nueva: es la que llevan viendo las pantallas.
     */
    private function retirarDuplicados(string $tabla, array $columnas): void
    {
        $pk = Schema::getColumnListing($tabla)[0];

        $repetidas = DB::table($tabla)
            ->select($columnas)
            ->selectRaw('MIN(' . $pk . ') as conservar, COUNT(*) as cuantas')
            ->groupBy($columnas)
            ->havingRaw('COUNT(*) > 1')
            ->get();

        foreach ($repetidas as $fila) {
            $q = DB::table($tabla)->where($pk, '<>', $fila->conservar);

            foreach ($columnas as $c) {
                $q->where($c, $fila->{$c});
            }

            $q->delete();
        }
    }
};
