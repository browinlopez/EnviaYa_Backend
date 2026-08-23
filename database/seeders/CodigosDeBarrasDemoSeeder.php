<?php

namespace Database\Seeders;

use App\Models\Product\Product;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Códigos de barras para poder probar el escáner.
 *
 * Los 587 productos que ya existían nacieron sin código —la columna no
 * existía— así que sin esto el buscador funciona pero el escáner no encuentra
 * nunca nada, y la mitad del trabajo no se puede ver.
 *
 * NO SON CÓDIGOS REALES y no pretenden serlo: se generan a partir del
 * identificador del producto con el prefijo 770, que es el de Colombia, y con
 * el dígito de verificación bien calculado para que un lector de verdad los
 * acepte. Sirven para probar; el día que se escanee un envase real, ese código
 * entra por el camino normal.
 *
 * Solo toca los que están vacíos: volver a correrlo no le cambia el código a
 * nada que ya lo tenga, ni siquiera si vino de una tienda.
 */
class CodigosDeBarrasDemoSeeder extends Seeder
{
    public function run(): void
    {
        $sinCodigo = Product::whereNull('barcode')->get();

        $this->command?->info("Productos sin código: {$sinCodigo->count()}");

        $usados = Product::whereNotNull('barcode')->pluck('barcode')->all();

        foreach ($sinCodigo as $p) {
            $codigo = $this->ean13(770_0000_00000 + (int) $p->products_id);

            // Con un catálogo grande dos productos podrían chocar; el único de
            // la base lo impediría a la mala, así que se comprueba antes.
            if (in_array($codigo, $usados, true)) {
                continue;
            }

            $usados[] = $codigo;

            DB::table('products')
                ->where('products_id', $p->products_id)
                ->update(['barcode' => $codigo]);
        }

        $this->command?->info(
            'Con código: ' . Product::whereNotNull('barcode')->count()
        );
    }

    /**
     * EAN-13 con su dígito de verificación.
     *
     * El último dígito no es decorativo: es lo que permite a un lector saber
     * que leyó bien. Sin él, un código de trece cifras inventadas lo rechaza
     * cualquier escáner de verdad y la prueba no valdría nada.
     */
    private function ean13(int $base): string
    {
        $doce = str_pad((string) ($base % 1_000_000_000_000), 12, '0', STR_PAD_LEFT);

        $suma = 0;

        for ($i = 0; $i < 12; $i++) {
            // Posiciones impares pesan tres veces; pares, una.
            $suma += (int) $doce[$i] * ($i % 2 === 0 ? 1 : 3);
        }

        $verificador = (10 - ($suma % 10)) % 10;

        return $doce . $verificador;
    }
}
