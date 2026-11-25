<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Support\ProductTypeSchema;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * ==========================================================================
 * LAS CATEGORÍAS QUE UNA DROGUERÍA SÍ USA.
 *
 * Farmacia tenía DOS: «Medicina» y «Cuidado Personal». Con dos categorías no
 * se puede filtrar nada: un comprador que busca algo para la tos y uno que
 * busca pañales acaban en la misma lista de cuatrocientos productos.
 *
 * Las de abajo son las que ordenan un mostrador de droguería de barrio de
 * verdad, no las de una cadena: lo que se pide mucho primero —dolor, gripa,
 * digestivo— y después lo que se compra planeado.
 *
 * --------------------------------------------------------------------------
 * NO PISA LAS DOS QUE YA ESTABAN.
 *
 * `updateOrInsert` por nombre, igual que `CategorySeeder`. «Medicina» y
 * «Cuidado Personal» se quedan: hay productos colgando de ellas y moverlos
 * sin mirar es cómo se pierde un catálogo.
 *
 * --------------------------------------------------------------------------
 * Y ESCRIBE EL PIVOTE, que es la parte que `CategorySeeder` NO hace.
 *
 * La fuente de verdad de qué categoría pertenece a qué tipo de negocio es
 * `category_category_business`; la columna `category.business_category_id` se
 * mantiene por compatibilidad. `CategorySeeder` sólo escribe la columna, y el
 * pivote lo rellenó una migración de una vez. Una categoría nueva sembrada
 * así quedaría fuera del pivote —o sea, invisible para el endpoint del
 * esquema y para la plantilla— y el fallo no se vería hasta abrir el panel.
 */
class CategoriasDeDrogueriaSeeder extends Seeder
{
    /** Nombre => para qué sirve, dicho como lo diría quien atiende. */
    private const CATEGORIAS = [
        'Dolor y fiebre' => 'Analgésicos y antipiréticos de venta libre',
        'Gripa y tos' => 'Antigripales, jarabes y pastillas para la garganta',
        'Digestivo' => 'Acidez, diarrea, estreñimiento y malestar estomacal',
        'Primeros auxilios' => 'Gasas, vendas, curitas, alcohol y antisépticos',
        'Vitaminas y suplementos' => 'Vitaminas, minerales y suplementos nutricionales',
        'Bebé' => 'Pañales, pañitos, cremas y fórmulas infantiles',
        'Higiene oral' => 'Cremas dentales, cepillos, enjuagues y seda',
        'Dermocosmética' => 'Cuidado de la piel, protectores solares y tratamientos',
        'Cuidado del adulto mayor' => 'Pañales de adulto, apoyos y cuidado en casa',
        'Ortopedia y bienestar' => 'Vendajes, soportes, termómetros y tensiómetros',
    ];

    public function run(): void
    {
        $tipo = ProductTypeSchema::TYPE_PHARMACY;

        foreach (self::CATEGORIAS as $nombre => $paraQue) {
            DB::table('category')->updateOrInsert(
                ['name' => $nombre],
                [
                    'description' => $paraQue,
                    'business_category_id' => $tipo,
                    'state' => 1,
                ],
            );

            $id = DB::table('category')->where('name', $nombre)->value('category_id');

            DB::table('category_category_business')->updateOrInsert(
                ['category_id' => $id, 'business_category_id' => $tipo],
                [],
            );
        }

        /*
         * Y las dos que ya existían, al pivote también. Están desde el
         * principio, pero si alguien sembró la base después de la migración
         * que lo rellenó, se quedaron fuera y nadie lo notó.
         */
        foreach (['Medicina', 'Cuidado Personal'] as $vieja) {
            $id = DB::table('category')->where('name', $vieja)->value('category_id');

            if ($id) {
                DB::table('category_category_business')->updateOrInsert(
                    ['category_id' => $id, 'business_category_id' => $tipo],
                    [],
                );
            }
        }
    }
}
