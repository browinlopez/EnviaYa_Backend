<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Support\ProductTypeSchema;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * ==========================================================================
 * UN RESTAURANTE Y UN ALMACÉN DE REPUESTOS, PARA PODER RECORRERLOS.
 *
 * De los cuatro oficios, dos nunca se habían usado: restaurante se quedó con
 * CERO negocios —el único que había se convirtió en la segunda droguería— y
 * repuestos tenía uno con el catálogo vacío.
 *
 * Eso quiere decir que sus campos, sus categorías y su vocabulario estaban
 * escritos pero sin estrenar. Y lo que aprendimos al llenar la droguería es
 * que los fallos no salen leyendo código: salieron cinco fugas de
 * vocabulario en cuanto hubo datos en pantalla.
 *
 * --------------------------------------------------------------------------
 * CON LOS CAMPOS NUEVOS PUESTOS, QUE ES LA MITAD DEL PUNTO.
 *
 * El restaurante lleva `minutos_preparacion` en cada plato —0 para una
 * gaseosa, 35 para un sancocho— y `acompanamientos`, que es lo que hace que
 * un corrientazo sea un corrientazo. Los repuestos llevan `posicion` y
 * `condicion`, que son las dos cosas que se preguntan en el mostrador.
 *
 * Sembrarlos vacíos sería como los pedidos huecos: la pantalla carga y no
 * enseña nada, y no se distingue de una rota.
 * ========================================================================== */
class RestauranteYRepuestosDemoSeeder extends Seeder
{
    /** [nombre, categoría, precio, minutos, acompañamientos, vegetariano, alérgenos] */
    private const CARTA = [
        ['Bandeja paisa', 'Almuerzos', 24000, 30, 'Frijoles, arroz, chicharrón, huevo, arepa y aguacate', false, 'Huevo'],
        ['Sancocho de gallina', 'Sopas y caldos', 22000, 35, 'Arroz, aguacate y limón', false, ''],
        ['Corrientazo del día', 'Almuerzos', 15000, 20, 'Sopa, arroz, principio, carne y jugo', false, ''],
        ['Mojarra frita', 'Almuerzos', 28000, 25, 'Arroz con coco, patacón y ensalada', false, 'Pescado'],
        ['Arroz con pollo', 'Almuerzos', 18000, 18, 'Ensalada y papas a la francesa', false, ''],
        ['Caldo de costilla', 'Sopas y caldos', 9000, 15, 'Arepa y cilantro', false, ''],
        ['Calentado con huevo', 'Desayunos', 11000, 12, 'Arepa, huevo y chocolate', false, 'Huevo'],
        ['Huevos pericos', 'Desayunos', 9500, 10, 'Arepa y café', true, 'Huevo'],
        ['Pollo asado entero', 'Asados y parrilla', 42000, 45, 'Papas criollas y ensalada', false, ''],
        ['Chuzo de res', 'Asados y parrilla', 14000, 20, 'Papa criolla y arepa', false, ''],
        ['Hamburguesa sencilla', 'Comidas Rápidas', 13000, 15, 'Papas a la francesa', false, 'Gluten, huevo'],
        ['Perro caliente', 'Comidas Rápidas', 9000, 8, 'Papas trituradas', false, 'Gluten'],
        ['Ensalada de la casa', 'Almuerzos', 12000, 10, 'Pan tostado', true, ''],
        ['Jugo natural en agua', 'Bebidas y Jugos', 4500, 5, '', true, ''],
        ['Gaseosa personal', 'Bebidas y Jugos', 3500, 0, '', true, ''],
        ['Limonada de coco', 'Bebidas y Jugos', 7000, 6, '', true, 'Leche'],
        ['Flan de caramelo', 'Postres', 6000, 0, '', true, 'Leche, huevo'],
        ['Arroz con leche', 'Postres', 5500, 0, '', true, 'Leche'],
        ['Porción de arroz', 'Adicionales', 3000, 3, '', true, ''],
        ['Porción de patacón', 'Adicionales', 4000, 8, '', true, ''],
    ];

    /** [nombre, categoría, precio, OEM, compatibilidad, posición, condición] */
    private const BODEGA = [
        ['Pastillas de freno delanteras', 'Frenos', 85000, '96405131', 'Chevrolet Spark 2010-2016, Aveo 2008-2014', 'Delantero', 'Original'],
        ['Pastillas de freno traseras', 'Frenos', 72000, '96405132', 'Chevrolet Spark 2010-2016', 'Trasero', 'Genérico'],
        ['Disco de freno', 'Frenos', 145000, '96574633', 'Renault Logan 2008-2015, Sandero 2010-2016', 'Delantero', 'Original'],
        ['Líquido de frenos DOT 3', 'Frenos', 18000, '', 'Universal', '', 'Original'],
        ['Amortiguador delantero', 'Suspensión y dirección', 198000, '96535163', 'Chevrolet Spark 2010-2016', 'Delantero izquierdo', 'Original'],
        ['Terminal de dirección', 'Suspensión y dirección', 56000, '96403099', 'Chevrolet Spark 2010-2016, Aveo 2008-2014', 'Derecho', 'Genérico'],
        ['Rótula inferior', 'Suspensión y dirección', 68000, '96535089', 'Renault Logan 2008-2015', 'Delantero', 'Genérico'],
        ['Correa de repartición', 'Motor', 112000, '96352652', 'Chevrolet Aveo 2008-2014', '', 'Original'],
        ['Juego de bujías', 'Motor', 48000, '96130723', 'Chevrolet Spark 2010-2016', '', 'Original'],
        ['Bomba de agua', 'Motor', 165000, '96352650', 'Chevrolet Aveo 2008-2014, Optra 2006-2012', '', 'Remanufacturado'],
        ['Batería 11 placas', 'Eléctrico y batería', 320000, '', 'Universal carro liviano', '', 'Original'],
        ['Motor de arranque', 'Eléctrico y batería', 410000, '96208782', 'Chevrolet Spark 2010-2016', '', 'Remanufacturado'],
        ['Bombillo H4', 'Luces', 22000, '', 'Universal', 'Delantero', 'Genérico'],
        ['Stop trasero', 'Luces', 135000, '96650842', 'Chevrolet Spark 2010-2016', 'Trasero derecho', 'Genérico'],
        ['Filtro de aceite', 'Filtros y lubricantes', 24000, '25183779', 'Chevrolet Spark 2010-2016, Aveo 2008-2014', '', 'Original'],
        ['Filtro de aire', 'Filtros y lubricantes', 32000, '96536696', 'Chevrolet Spark 2010-2016', '', 'Genérico'],
        ['Aceite de motor 20W50', 'Filtros y lubricantes', 62000, '', 'Universal', '', 'Original'],
        ['Llanta 175/70 R13', 'Llantas y rines', 215000, '', 'Universal R13', '', 'Original'],
        ['Espejo retrovisor', 'Carrocería', 95000, '96652078', 'Chevrolet Spark 2010-2016', 'Izquierdo', 'Genérico'],
        ['Plumillas limpiabrisas', 'Carrocería', 38000, '', 'Universal 18 pulgadas', 'Delantero', 'Genérico'],
        ['Pastillas de freno moto', 'Para moto', 42000, '', 'Bajaj Pulsar 180, Boxer CT100', 'Delantero', 'Genérico'],
        ['Kit de arrastre moto', 'Para moto', 118000, '', 'Bajaj Boxer CT100', '', 'Original'],
    ];

    public function run(): void
    {
        $restaurante = $this->negocio(
            'Sabor Costeño Restaurante',
            ProductTypeSchema::TYPE_RESTAURANT,
            'Calle 72 #41-18',
            'Comida casera y corrientazo. Almuerzos desde las 11 a. m.',
            10.9941,
            -74.8017,
        );

        $almacen = $this->negocio(
            'Repuestos El Rodamiento',
            ProductTypeSchema::TYPE_CAR_PARTS,
            'Carrera 38 #74-23',
            'Repuestos para carro y moto. Frenos, suspensión y eléctrico.',
            10.9896,
            -74.7921,
        );

        $platos = $this->surtido($restaurante, self::CARTA, function (int $productoId, array $f) {
            DB::table('restaurant_products')->updateOrInsert(
                ['products_id' => $productoId],
                [
                    'minutos_preparacion' => $f[3],
                    'acompanamientos' => $f[4] ?: null,
                    'es_vegetariano' => $f[5],
                    'is_vegan' => false,
                    'is_gluten_free' => false,
                    'allergens' => $f[6] ?: null,
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
            );
        });

        $piezas = $this->surtido($almacen, self::BODEGA, function (int $productoId, array $f) {
            DB::table('car_parts_products')->updateOrInsert(
                ['products_id' => $productoId],
                [
                    'oem_code' => $f[3] ?: null,
                    'compatibility' => $f[4] ?: null,
                    'posicion' => $f[5] ?: null,
                    'condicion' => $f[6] ?: null,
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
            );
        });

        $this->command?->info("Restaurante (negocio {$restaurante}): {$platos} platos en la carta.");
        $this->command?->info("Almacén (negocio {$almacen}): {$piezas} referencias en bodega.");
    }

    /* ------------------------------------------------------------------ */

    /**
     * El negocio y su dueño, sin pisar nada si ya existen.
     *
     * Dueño propio y NO uno de los que ya hay: lo que se quiere mirar es cada
     * oficio por separado. Colgarlos de Yeimy —que ya lleva dos droguerías—
     * mezclaría el problema del multiempresa con el del tipo de negocio, y
     * cuando algo saliera mal no se sabría cuál de los dos lo causó.
     */
    private function negocio(string $nombre, int $tipo, string $dir, string $desc, float $lat, float $lng): int
    {
        $existente = DB::table('business')->where('name', $nombre)->value('busines_id');

        if ($existente) {
            DB::table('business')->where('busines_id', $existente)->update([
                'type' => $tipo,
                'address' => $dir,
                'description' => $desc,
                'latitude' => $lat,
                'longitude' => $lng,
                'state' => 1,
            ]);

            /*
             * Y SE SIGUE AL DUEÑO, aunque el negocio ya estuviera.
             *
             * La primera versión devolvía aquí y se saltaba el alta del dueño:
             * «Repuestos El Rodamiento» ya existía, así que el usuario nuevo se
             * creó sin un solo negocio y al entrar el panel lo devolvía al
             * login. Un dueño huérfano no da error: da una pantalla de entrada
             * que parece que la contraseña está mal.
             */
            $this->dueno($tipo, (int) $existente);

            return (int) $existente;
        }

        $id = (int) DB::table('business')->insertGetId([
            'name' => $nombre,
            'type' => $tipo,
            'address' => $dir,
            'description' => $desc,
            'latitude' => $lat,
            'longitude' => $lng,
            'qualification' => 0,
            'state' => 1,
        ], 'busines_id');

        $this->dueno($tipo, $id);

        return $id;
    }

    /** El dueño de este oficio, y que el negocio quede atado a él. */
    private function dueno(int $tipo, int $negocio): void
    {
        $correo = $tipo === ProductTypeSchema::TYPE_RESTAURANT
            ? 'cocina@demo.example.com'
            : 'mostrador@demo.example.com';

        $user = DB::table('user')->where('email', $correo)->first(['user_id']);

        if (! $user) {
            $userId = DB::table('user')->insertGetId([
                'name' => $tipo === ProductTypeSchema::TYPE_RESTAURANT ? 'Ligia Barraza' : 'Hernán Movilla',
                'email' => $correo,
                'password' => bcrypt('Demo2026*'),
                'rol' => 2,
                'state' => 1,

                /*
                 * VERIFICADO, o no puede entrar.
                 *
                 * `AuthController@login` responde «Debes verificar tu correo
                 * antes de iniciar sesion» si esto esta en nulo, y el panel
                 * devuelve al login sin decir por que: se lee como contrasena
                 * equivocada. A un usuario de demostracion no le va a llegar
                 * ningun correo, asi que nace verificado.
                 */
                'email_verified_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ], 'user_id');
        } else {
            $userId = (int) $user->user_id;
        }

        $ownerId = DB::table('owner')->where('user_id', $userId)->value('owner_id')
            ?: DB::table('owner')->insertGetId(['user_id' => $userId, 'state' => 1], 'owner_id');

        DB::table('owner_busines')->updateOrInsert(
            ['owner_id' => $ownerId, 'busines_id' => $negocio],
            ['state' => 1],
        );
    }

    /**
     * Los productos del catálogo compartido y su fila en la tienda.
     *
     * Sin código de barras: un plato no tiene EAN, y una pieza de repuesto se
     * identifica por su código OEM, que ya va en su propia columna. Inventar
     * códigos ocuparía para siempre valores del catálogo compartido —es
     * ÚNICO—, que es el mismo motivo por el que el Excel de ejemplo de la
     * droguería los deja en blanco.
     */
    private function surtido(int $negocio, array $filas, callable $extra): int
    {
        $puestos = 0;

        foreach ($filas as $f) {
            [$nombre, $categoria, $precio] = $f;

            $categoriaId = DB::table('category')->where('name', $categoria)->value('category_id');

            if (! $categoriaId) {
                continue;
            }

            $productoId = DB::table('products')->where('name', $nombre)->value('products_id');

            if (! $productoId) {
                $productoId = DB::table('products')->insertGetId([
                    'name' => $nombre,
                    'category_id' => $categoriaId,
                    'state' => 1,
                    'origen' => 'equipo',
                    'created_at' => now(),
                    'updated_at' => now(),
                ], 'products_id');
            }

            DB::table('products_business')->updateOrInsert(
                ['busines_id' => $negocio, 'products_id' => (int) $productoId],
                [
                    'price' => $precio,
                    'sale_unit' => 'unidad',
                    'amount' => 20,
                    'stock_weight' => 0,
                    'qualification' => 0,
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
            );

            $extra((int) $productoId, $f);

            $puestos++;
        }

        return $puestos;
    }
}
