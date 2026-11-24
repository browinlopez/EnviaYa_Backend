<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Product\PharmacyProduct;
use App\Support\ProductTypeSchema;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * ==========================================================================
 * EL MOSTRADOR DE UNA DROGUERÍA DE BARRIO, COMPLETO.
 *
 * Doscientas referencias de `database/data/catalogo_de_drogueria.php`. La
 * droguería tenía cuarenta y seis —las del Excel de ejemplo— y con eso no se
 * puede mirar nada de verdad: ni el buscador, ni las categorías, ni la
 * paginación, ni qué pasa cuando un tendero tiene que encontrar algo entre
 * doscientos y no entre cuarenta.
 *
 * --------------------------------------------------------------------------
 * EN LAS DOS SEDES, CON PRECIOS DISTINTOS.
 *
 * El centro lleva el catálogo entero; la del norte, la mitad y un 8 % más
 * cara. Es lo mismo que ya hacía `DrogueriaDemoSeeder` con los cuarenta y
 * seis, y por el mismo motivo: dos sedes idénticas no enseñan si el panel
 * está mostrando la sede correcta o siempre la misma.
 *
 * --------------------------------------------------------------------------
 * SIN CÓDIGO DE BARRAS NI REGISTRO INVIMA.
 *
 * `products.barcode` es ÚNICO en el catálogo COMPARTIDO de toda la
 * plataforma: inventar doscientos EANs los ocuparía para siempre y el
 * producto real nunca se encontraría al escanearlo. Y un número de registro
 * sanitario inventado es un dato regulatorio inventado.
 *
 * Lo que sí es cierto: nombres, marcas, presentaciones, contenidos y, sobre
 * todo, QUÉ PIDE FÓRMULA. Los antibióticos, antihipertensivos y
 * antidiabéticos están marcados porque la ley lo exige, no al azar.
 *
 * Los PRECIOS son verosímiles, no reales.
 */
class CatalogoDeDrogueriaSeeder extends Seeder
{
    public function run(): void
    {
        $sedes = DB::table('business')
            ->where('type', ProductTypeSchema::TYPE_PHARMACY)
            ->orderBy('busines_id')
            ->pluck('busines_id')
            ->all();

        if ($sedes === []) {
            $this->command?->error('No hay droguerías en esta base.');

            return;
        }

        $catalogo = require database_path('data/catalogo_de_drogueria.php');

        $categorias = DB::table('category')->pluck('category_id', 'name');

        $puestos = 0;
        $sinCategoria = [];

        foreach ($catalogo as $i => $p) {
            [$nombre, $marca, $categoria, $precio, $presentacion, $contenido,
                $formula, $principio, $dosis, $frio] = $p;

            if (! isset($categorias[$categoria])) {
                $sinCategoria[$categoria] = true;

                continue;
            }

            /*
             * POR NOMBRE Y MARCA, no sólo por nombre.
             *
             * «Acetaminofén 500 mg» lo hacen MK y Genfar, y son dos productos
             * distintos con dos precios. Buscar sólo por nombre haría que el
             * segundo pisara al primero y el catálogo se quedara corto sin que
             * nadie lo notara.
             */
            $productoId = DB::table('products')
                ->where('name', $nombre)
                ->where('brand', $marca)
                ->value('products_id');

            if (! $productoId) {
                $productoId = DB::table('products')->insertGetId([
                    'name' => $nombre,
                    'brand' => $marca,
                    'category_id' => $categorias[$categoria],
                    'content' => $contenido,
                    'presentation' => $presentacion,
                    'requiere_frio' => $frio,
                    'state' => 1,
                    'origen' => 'equipo',
                    'created_at' => now(),
                    'updated_at' => now(),
                ], 'products_id');
            } else {
                DB::table('products')->where('products_id', $productoId)->update([
                    'brand' => $marca,
                    'content' => $contenido,
                    'presentation' => $presentacion,
                    'requiere_frio' => $frio,
                ]);
            }

            /* Lo que sólo una droguería pregunta. */
            if ($formula || $principio !== '' || $dosis !== '') {
                PharmacyProduct::updateOrCreate(
                    ['products_id' => (int) $productoId],
                    [
                        'requiere_formula' => $formula,
                        'active_ingredient' => $principio ?: null,
                        'dosage' => $dosis ?: null,
                    ],
                );
            }

            $this->aLaSede((int) $sedes[0], (int) $productoId, (float) $precio, $i);

            /* La del norte: uno de cada dos, un 8 % más caro. */
            if (isset($sedes[1]) && $i % 2 === 0) {
                $this->aLaSede(
                    (int) $sedes[1],
                    (int) $productoId,
                    round(((float) $precio * 1.08) / 100) * 100,
                    $i,
                );
            }

            $puestos++;
        }

        $this->command?->info("{$puestos} productos de droguería en el catálogo.");

        foreach ($sedes as $sede) {
            $nombre = DB::table('business')->where('busines_id', $sede)->value('name');
            $n = DB::table('products_business')->where('busines_id', $sede)->count();
            $this->command?->line("  {$nombre}: {$n} referencias");
        }

        $this->command?->line('  '.PharmacyProduct::where('requiere_formula', true)->count().' piden fórmula médica');

        if ($sinCategoria !== []) {
            $this->command?->newLine();
            $this->command?->warn('Categorías que no existen en la base, y sus productos NO entraron:');
            $this->command?->line('  '.implode(' · ', array_keys($sinCategoria)));
            $this->command?->line('  Siembra antes: php artisan db:seed --class=CategoriasDeDrogueriaSeeder');
        }
    }

    /** El producto en el surtido de una sede, con sus existencias. */
    private function aLaSede(int $sede, int $producto, float $precio, int $i): void
    {
        /* Unas pocas agotadas y unas pocas por acabarse: una droguería donde
           todo está disponible no enseña los contadores de la portada. */
        $cantidad = match (true) {
            $i % 37 === 0 => 0,
            $i % 23 === 0 => 3,
            default => 8 + ($i * 7) % 40,
        };

        DB::table('products_business')->updateOrInsert(
            ['busines_id' => $sede, 'products_id' => $producto],
            [
                'price' => $precio,
                'sale_unit' => 'unidad',
                'amount' => $cantidad,
                'stock_weight' => 0,
                'qualification' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        );
    }
}
