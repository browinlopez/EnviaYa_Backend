<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Support\ProductTypeSchema;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * ==========================================================================
 * LAS CATEGORÍAS DE UNA TIENDA DE BARRIO, SACADAS DEL CATÁLOGO DE VERDAD.
 *
 * No son una taxonomía inventada. Salen de leer los 559 productos REALES
 * —`origen = 'equipo'`, sin contar los 5.000 sintéticos de la prueba de
 * carga— y contar qué hay:
 *
 *     leche, yogurt, huevos .......... 216
 *     galletas y pasabocas ........... 102
 *     gaseosas, jugos, energizantes ... 97
 *     enlatados y salsas .............. 74 + ~50 sin clasificar
 *     granos y mercado ................ 33
 *     cuidado personal ................ 25
 *     pan ............................. ~15
 *     aseo del hogar .................. 17
 *     velones ......................... 12
 *     mascotas ......................... 8
 *     cerveza .......................... 2
 *
 * --------------------------------------------------------------------------
 * LAS CINCO QUE HABÍA ESTABAN MAL APLICADAS, Y ESO SE VE EN LOS DATOS.
 *
 * «Bebidas» tenía mayonesa, leche condensada y galletas Ducales. «Aseo y
 * Limpieza» mezclaba detergente con crema dental y toallas higiénicas, que
 * son dos estantes distintos y dos búsquedas distintas.
 *
 * Así que esto hace dos cosas: añade las que faltaban Y mueve los productos
 * reales que estaban en la equivocada.
 *
 * --------------------------------------------------------------------------
 * NUEVE Y NO QUINCE.
 *
 * Con dos no se filtra nada; con quince, elegir cuesta más que buscar. Lo
 * que no aparece en el catálogo no se inventa: NO hay «Frutas y verduras»
 * —se buscó: los aciertos eran salsa de tomate y atún con limón, ninguna
 * fruta de verdad— ni «Carnes» ni «Helados» ni «Cigarrillos».
 *
 * «Bebé» tampoco: seis compotas y coladas no sostienen un estante propio, y
 * caben en Cuidado personal hasta que sean más.
 *
 * --------------------------------------------------------------------------
 * «CUIDADO PERSONAL» SE COMPARTE CON FARMACIA, NO SE DUPLICA.
 *
 * Ya existe para droguería. El pivote `category_category_business` es único
 * por PAREJA (categoría, tipo) —comprobado—, así que la misma categoría puede
 * servir a los dos oficios. Crear una «Cuidado Personal» de tienda al lado
 * daría dos categorías con el mismo nombre, y el día que alguien busque crema
 * dental la mitad de los productos estaría en cada una.
 * ========================================================================== */
class CategoriasDeTiendaSeeder extends Seeder
{
    /** Nombre => para qué sirve, dicho como lo diría quien atiende. */
    private const NUEVAS = [
        'Enlatados y salsas' => 'Sardinas, atún, arveja, maíz, salsas, mayonesa y mermeladas',
        'Panadería' => 'Pan de leche, pan galleta, cucas y ponqués',
        'Cerveza y licores' => 'Cerveza, aguardiente, ron y vino',
        'Mascotas' => 'Concentrado y comida para perros y gatos',
    ];

    /**
     * Las que ya existen y se quedan como están.
     *
     * NO se renombran aunque «Snacks» sea una palabra rara en un barrio: hay
     * productos colgando de ellas, pruebas que fijan sus nombres —
     * `PlantillaDeCatalogoTest` afirma que `Listas!A2` es «Abarrotes»— y el
     * nombre de una categoría es lo que el comprador ya aprendió a buscar.
     */
    private const QUE_SE_QUEDAN = ['Abarrotes', 'Bebidas', 'Lácteos y Huevos', 'Snacks', 'Aseo y Limpieza'];

    /**
     * Lo que se mueve de estante, y SOLO si el nombre no deja duda.
     *
     * Por palabras y no a mano: son quinientos productos. Pero conservador a
     * propósito —«CREMA DENTAL» sí, «CREMA» a secas no—, porque una
     * reclasificación equivocada es peor que la categoría equivocada de
     * antes: al menos aquélla era consistente.
     *
     * Y solo toca los REALES. Los 5.000 de `origen = 'prueba-carga'` están
     * repartidos al azar y moverlos sería ordenar ruido.
     */
    private const MUDANZAS = [
        'Cuidado personal' => '/CREMA DENTAL|CEPILLO DENTAL|DESODORANTE|SHAMPOO|TOALLAS? (NOSOTRAS|HIGIENIC)|YODORA|AFEITAR|ENJUAGUE BUCAL|TALCO|PA[ÑN]AL/u',
        'Enlatados y salsas' => '/SARDINA|AT[UÚ]N|ARVEJA|ALVEJA|ALVERJA|MA[IÍ]Z TIERNO|MERMELADA|MAYONESA|MAYONEZA|SALSA DE TOMATE|PASTA DE TOMATE|SALSA NEGRA|MOSTAMIEL|TARTARA|ENSALADA .*SAN JORGE|LATA ZENU|ALAMAR/u',
        'Panadería' => '/^PAN |PAN GALLETA|PAN ALMOHADILLA|PAN DE LECHE|PONQUE|CUCA/u',
        'Cerveza y licores' => '/CERVEZA|AGUARDIENTE|CLUB COLOMBIA|CORONA|CORONITA|POKER|AGUILA LIGHT/u',
        'Mascotas' => '/DOG CHOW|DONKAN|PURINA|CAT CHOW|RINGO|CHUNKY|COMIDA H[UÚ]MEDA FELIX/u',
    ];

    public function run(): void
    {
        $tipo = ProductTypeSchema::TYPE_GROCERY;

        $ids = [];

        foreach (self::NUEVAS as $nombre => $paraQue) {
            $ids[$nombre] = $this->categoria($nombre, $paraQue, $tipo);
        }

        /* Cuidado personal: la de farmacia, también para tienda. */
        $cuidado = DB::table('category')->where('name', 'Cuidado Personal')->value('category_id');

        if ($cuidado) {
            DB::table('category_category_business')->updateOrInsert(
                ['category_id' => $cuidado, 'business_category_id' => $tipo],
                [],
            );

            $ids['Cuidado personal'] = (int) $cuidado;
        }

        /* Y las cinco de siempre, al pivote por si alguna se quedó fuera. */
        foreach (self::QUE_SE_QUEDAN as $vieja) {
            $id = DB::table('category')->where('name', $vieja)->value('category_id');

            if ($id) {
                DB::table('category_category_business')->updateOrInsert(
                    ['category_id' => $id, 'business_category_id' => $tipo],
                    [],
                );
            }
        }

        $movidos = $this->mudanza($ids, $tipo);

        $this->command?->info('Tienda: '.DB::table('category_category_business')->where('business_category_id', $tipo)->count().' categorías.');
        $this->command?->line("  {$movidos} productos reales movidos al estante que les toca.");

        foreach (DB::table('category as c')->join('category_category_business as cb', 'cb.category_id', '=', 'c.category_id')->where('cb.business_category_id', $tipo)->orderBy('c.name')->pluck('c.name', 'c.category_id') as $id => $n) {
            $cuantos = DB::table('products')->where('category_id', $id)->where('state', 1)->where('origen', 'equipo')->count();
            $this->command?->line(sprintf('    %-22s %s reales', $n, $cuantos));
        }
    }

    /* ------------------------------------------------------------------ */

    private function categoria(string $nombre, string $paraQue, int $tipo): int
    {
        DB::table('category')->updateOrInsert(
            ['name' => $nombre],
            ['description' => $paraQue, 'business_category_id' => $tipo, 'state' => 1],
        );

        $id = (int) DB::table('category')->where('name', $nombre)->value('category_id');

        DB::table('category_category_business')->updateOrInsert(
            ['category_id' => $id, 'business_category_id' => $tipo],
            [],
        );

        return $id;
    }

    /** @param  array<string, int>  $ids */
    private function mudanza(array $ids, int $tipo): int
    {
        $deTienda = DB::table('category_category_business')
            ->where('business_category_id', $tipo)
            ->pluck('category_id');

        $productos = DB::table('products')
            ->where('state', 1)
            ->where('origen', 'equipo')
            ->whereIn('category_id', $deTienda)
            ->get(['products_id', 'name']);

        $movidos = 0;

        foreach ($productos as $p) {
            $nombre = mb_strtoupper($p->name);

            foreach (self::MUDANZAS as $destino => $patron) {
                if (! isset($ids[$destino]) || ! preg_match($patron, $nombre)) {
                    continue;
                }

                DB::table('products')
                    ->where('products_id', $p->products_id)
                    ->where('category_id', '<>', $ids[$destino])
                    ->update(['category_id' => $ids[$destino]]);

                $movidos++;

                break; // el primero que case manda: no se mueve dos veces
            }
        }

        return $movidos;
    }
}
