<?php

declare(strict_types=1);

namespace App\Http\Resources\Producto;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * UN PRODUCTO EN «LOS MEJORES NEGOCIOS», QUE NO ES EL MISMO PRODUCTO QUE EN LA
 * VITRINA. Y eso es un hallazgo, no un detalle de organizacion.
 *
 * `ConsultaDeNegociosController` tiene tres respuestas de negocio. Dos
 * —`index` (la vitrina) y `show` (la ficha)— sirven el producto con SIETE
 * campos, por `ProductoDelNegocioResource`. La tercera
 * —`indexByQualification`, que atiende `GET /businesses/top-businesses` y
 * `GET /top-businesses-free`— tenia su propia copia con DOCE, escrita a mano
 * dentro del controlador.
 *
 * Comprobado contra las respuestas de verdad, no leyendo el codigo:
 *
 *   solo en top-businesses: category, group, variante, sale_unit, min_qty,
 *                           step_qty
 *   solo en index:          category_id
 *
 * LO QUE ESO SIGNIFICA PARA QUIEN COMPRA. `sale_unit`, `min_qty` y `step_qty`
 * son los campos con los que la app decide si ofrece el +/- de siempre o los
 * botones de plata y de porcion —«3.000 de queso», «500 g»— para lo que se
 * vende al peso. Por la vitrina principal no llegan. Y `variante` y `group`
 * son los que hacen que ocho presentaciones del mismo producto se pinten como
 * UNA tarjeta con chips en vez de ocho tarjetas.
 *
 * O sea que, segun por donde entre el comprador, el mismo producto se ve de
 * dos maneras distintas, y la vitrina principal —que es la lectura de mas
 * trafico de la plataforma— es la que se queda corta.
 *
 * ESTO ES EXACTAMENTE LO QUE EL SERVICIO VIEJO EXISTIA PARA EVITAR. Su
 * cabecera decia: «el dia que alguien anada un campo al listado y no a la
 * ficha, la app recibe un negocio distinto segun por donde entre, y el fallo
 * aparece en una pantalla que nadie toco». Paso: los campos del peso se
 * anadieron a una de las tres.
 *
 * LAS DOS FORMAS SE CONSERVAN TAL CUAL, y no se unifican aqui. Unificarlas es
 * cambiar lo que reciben dos apps publicadas en las tiendas —anadir seis
 * campos a la vitrina o quitarselos a los destacados— y eso es una decision
 * de producto con consecuencias en pantalla, no una limpieza. Lo que se gana
 * moviendolas es que ahora estan las dos a la vista, con la diferencia medida
 * y escrita.
 */
class ProductoDestacadoResource extends JsonResource
{
    public function __construct(
        mixed $producto,
        private readonly bool $afiliado,
        private readonly mixed $userId,
    ) {
        parent::__construct($producto);
    }

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /*
         * Los nombres de dentro se conservan —`$product`, `$isAffiliated`,
         * `$userId`— para que el cuerpo sea el mismo texto que habia en el
         * controlador, caracter por caracter.
         */
        $product = $this->resource;
        $isAffiliated = $this->afiliado;
        $userId = $this->userId;

        return [
            'product_id' => $product->products_id,
            'name' => $product->name,
            'description' => $product->description,
            'category' => $product->category ? $product->category->name : null,
            'image' => $product->image,
            'state' => (bool) $product->state,
            /*
             * EL GRUPO DE VARIANTES, PARA QUE LA APP PINTE UNA
             * TARJETA Y NO OCHO.
             *
             * Va junto al producto y no en una lista aparte porque
             * lo que se vende sigue siendo ESTE producto: el grupo
             * solo dice de qué tarjeta cuelga y qué pone el chip.
             * Así una app vieja ignora los dos campos y sigue
             * funcionando igual que ayer.
             *
             * `variante` llega aunque no haya grupo: es el texto
             * de la presentación y sirve para mostrarlo bajo el
             * nombre aunque el producto sea único.
             */
            'variante' => $product->variante,
            'group' => $product->group ? [
                'group_id' => (int) $product->group->group_id,
                'name' => $product->group->name,
                'image' => $product->group->image,
            ] : null,
            // Si no hay user_id → precio 0
            // Si hay user_id → mostrar precio solo si está afiliado
            'price' => $userId ? ($isAffiliated ? ($product->pivot->price ?? 0) : 0) : 0,
            /*
             * COMO SE VENDE: por unidad o al peso. Con una unidad
             * de peso, `price` es el precio por esa medida y la
             * pantalla ofrece botones de plata --«3.000 de
             * queso»-- y de porcion en vez del +/- de siempre.
             */
            'sale_unit' => $product->pivot->sale_unit ?? 'unidad',
            'min_qty' => $product->pivot->min_qty !== null ? (float) $product->pivot->min_qty : null,
            'step_qty' => $product->pivot->step_qty !== null ? (float) $product->pivot->step_qty : null,
            // Si se puede pedir por plata: «$1.500 de queso».
            'por_valor' => (bool) ($product->pivot->por_valor ?? false),
        ];
    }

    /**
     * El catalogo de un negocio destacado, con la misma regla de precio.
     *
     * @return list<array<string, mixed>>
     */
    public static function deLaTienda(mixed $negocio, bool $afiliado, mixed $userId): array
    {
        return $negocio->products
            ->map(fn ($p) => (new self($p, $afiliado, $userId))->resolve())
            ->all();
    }
}
