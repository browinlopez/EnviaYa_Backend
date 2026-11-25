<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * LA CATEGORÍA CON LA QUE UN NEGOCIO ENSEÑA UN PRODUCTO.
 *
 * La del negocio (`products_business.category_id`) si la tiene; si no, la
 * del producto. Ver la migración `la_categoria_de_cada_negocio`: el Vick
 * VapoRub es «Gripa y tos» en la droguería y «Botiquín y medicamentos» en la
 * tienda de la esquina.
 *
 * Los nombres se guardan en memoria mientras dura la petición: un catálogo
 * de cinco mil productos usa una docena de categorías, y preguntar el nombre
 * cinco mil veces sería absurdo.
 */
final class CategoriaDelNegocio
{
    /** @var array<int, string|null> */
    private static array $nombres = [];

    /**
     * @param  int|null  $delNegocio  `products_business.category_id`
     * @param  int|null  $delProducto  `products.category_id`
     * @return array{category_id: int, name: string}|null
     */
    public static function de(int|string|null $delNegocio, int|string|null $delProducto): ?array
    {
        $id = (int) ($delNegocio ?: $delProducto);
        if (! $id) {
            return null;
        }

        if (! array_key_exists($id, self::$nombres)) {
            self::$nombres[$id] = DB::table('category')->where('category_id', $id)->value('name');
        }

        $nombre = self::$nombres[$id];

        return $nombre === null ? null : ['category_id' => $id, 'name' => $nombre];
    }

    /** Para las pruebas: que una no herede los nombres de otra. */
    public static function olvidar(): void
    {
        self::$nombres = [];
    }
}
