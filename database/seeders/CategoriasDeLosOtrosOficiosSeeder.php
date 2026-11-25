<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Support\ProductTypeSchema;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * ==========================================================================
 * LAS CATEGORÍAS DE RESTAURANTE Y DE REPUESTOS.
 *
 * Repuestos tenía DOS —«Repuestos» y «Accesorios»— y una de ellas se llamaba
 * igual que el tipo de negocio. Con dos no se filtra nada: es el mismo
 * problema que tenía farmacia, que pasó de dos a doce.
 *
 * Restaurante tenía cinco razonables —Almuerzos, Comidas Rápidas, Bebidas y
 * Jugos, Postres, Adicionales— a las que les faltaban las dos comidas del
 * día que no son el almuerzo.
 *
 * --------------------------------------------------------------------------
 * ORDENADAS POR CÓMO BUSCA QUIEN COMPRA, NO POR CÓMO SE ARCHIVA.
 *
 * En repuestos, nadie busca «componentes del sistema de suspensión»: busca
 * «amortiguadores». Y lo primero que pregunta quien entra a un almacén de
 * repuestos es si es para carro o para moto, así que eso va en el nombre.
 *
 * --------------------------------------------------------------------------
 * LAS QUE YA ESTABAN NO SE TOCAN.
 *
 * «Repuestos» y «Accesorios» tienen 714 productos colgando entre las dos.
 * Renombrarlas o apagarlas los dejaría huérfanos; se quedan como el cajón de
 * entrada del que se reclasifica después.
 */
class CategoriasDeLosOtrosOficiosSeeder extends Seeder
{
    /** Las dos comidas que faltaban, y el café de la tarde. */
    private const RESTAURANTE = [
        'Desayunos' => 'Huevos, caldo, arepa, calentado y chocolate',
        'Sopas y caldos' => 'Sancocho, mondongo, caldo de costilla y cremas',
        'Asados y parrilla' => 'Carnes a la parrilla, pollo asado y chuzos',
    ];

    /**
     * Repuestos, por sistema del vehículo pero dicho como se pide.
     *
     * «Para moto» aparte y no repartido: un almacén que vende las dos cosas
     * necesita separarlas en la primera pantalla, porque un cliente que busca
     * pastillas de freno de moto no quiere ver las de carro.
     */
    private const REPUESTOS = [
        'Frenos' => 'Pastillas, discos, bandas, líquido y cilindros',
        'Suspensión y dirección' => 'Amortiguadores, espirales, terminales y rótulas',
        'Motor' => 'Empaques, correas, bujías, filtros de aceite y bombas',
        'Eléctrico y batería' => 'Baterías, alternadores, motores de arranque y fusibles',
        'Luces' => 'Bombillos, faros, stops, direccionales y exploradoras',
        'Filtros y lubricantes' => 'Aceite, filtros de aire y de gasolina, refrigerante',
        'Llantas y rines' => 'Llantas, neumáticos, rines y válvulas',
        'Carrocería' => 'Espejos, manijas, plumillas, guardapolvos y parachoques',
        'Para moto' => 'Repuestos y accesorios de motocicleta',
    ];

    public function run(): void
    {
        $puestas = [
            ProductTypeSchema::TYPE_RESTAURANT => self::RESTAURANTE,
            ProductTypeSchema::TYPE_CAR_PARTS => self::REPUESTOS,
        ];

        foreach ($puestas as $tipo => $lista) {
            foreach ($lista as $nombre => $paraQue) {
                $this->categoria($nombre, $paraQue, $tipo);
            }

            $cuantas = DB::table('category_category_business')
                ->where('business_category_id', $tipo)
                ->count();

            $nombres = DB::table('category as c')
                ->join('category_category_business as cb', 'cb.category_id', '=', 'c.category_id')
                ->where('cb.business_category_id', $tipo)
                ->orderBy('c.name')
                ->pluck('c.name')
                ->implode(' · ');

            $comoSeLlama = DB::table('category_business')->where('id', $tipo)->value('name');

            $this->command?->info("{$comoSeLlama}: {$cuantas} categorías");
            $this->command?->line("  {$nombres}");
        }
    }

    private function categoria(string $nombre, string $paraQue, int $tipo): void
    {
        DB::table('category')->updateOrInsert(
            ['name' => $nombre],
            ['description' => $paraQue, 'business_category_id' => $tipo, 'state' => 1],
        );

        $id = DB::table('category')->where('name', $nombre)->value('category_id');

        DB::table('category_category_business')->updateOrInsert(
            ['category_id' => $id, 'business_category_id' => $tipo],
            [],
        );
    }
}
