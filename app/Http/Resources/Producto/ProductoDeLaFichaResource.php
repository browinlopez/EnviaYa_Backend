<?php

declare(strict_types=1);

namespace App\Http\Resources\Producto;

use App\Support\CategoriaDelNegocio;
use App\Support\ProductTypeSchema;
use App\Support\UnidadDeVenta;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * EL PRODUCTO EN LA FICHA DE UN NEGOCIO. La cuarta de seis formas.
 *
 * Era el `map` de `ConsultaDeNegociosController::show()`, movido aqui sin
 * tocar un campo. Doce campos.
 *
 * ES LA UNICA DE LAS SEIS QUE TRAE `promocion`, y ese campo tiene una regla
 * dentro: nunca se pone sobre lo que se vende al peso, porque el motor de
 * descuentos excluye esas lineas y la etiqueta prometeria una rebaja que el
 * carrito no da. Esa exclusion estuvo rota hasta el 11 de noviembre de 2026
 * —`Business::products()` no traia `sale_unit`, asi que el filtro veia
 * siempre `unidad` y no excluia nada—; hay prueba.
 *
 * SU PRECIO COMPRUEBA LA AFILIACION PERO NO EL `userId`: escribe
 * `$estaAfiliado ? ($pivot->price ?? 0) : 0`, mientras la vitrina y los
 * destacados escriben `$userId && $afiliado ? ... : 0`. Sin sesion no hay
 * afiliacion posible, asi que en la practica coinciden; lo que no coincide es
 * el texto, y de los seis sitios tres comprueban y tres no.
 *
 * La promocion se RECIBE resuelta. `show()` trae las promociones vigentes de
 * la tienda en una consulta y un cierre decide cual alcanza a cada producto:
 * resolverlo dentro del Resource serian dos consultas por producto.
 */
class ProductoDeLaFichaResource extends JsonResource
{
    /** @param  callable(int): (array<string, mixed>|null)  $promoDe */
    public function __construct(
        mixed $producto,
        private readonly bool $estaAfiliado,
        private $promoDe,
        private readonly ?int $tipoDeNegocio = null,
    ) {
        parent::__construct($producto);
    }

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /*
         * Los nombres se conservan —`$product`, `$estaAfiliado`, `$promoDe`—
         * para que el cuerpo sea el mismo texto que habia en el controlador.
         */
        $product = $this->resource;
        $estaAfiliado = $this->estaAfiliado;
        $promoDe = $this->promoDe;

        return [
            'product_id' => $product->products_id,
            'name' => $product->name,
            'description' => $product->description,
            /* La categoría con la que ESTE negocio enseña el producto: la
               suya si la tiene, la del producto si no. La ficha agrupa por
               el nombre. */
            'category_id' => CategoriaDelNegocio::de($product->pivot->category_id ?? null, $product->category_id)['category_id'] ?? null,
            'category' => CategoriaDelNegocio::de($product->pivot->category_id ?? null, $product->category_id)['name'] ?? null,
            'image' => $product->image,
            'state' => (bool) $product->state,
            /*
             * CADENA CON DOS DECIMALES, QUE ES LO QUE YA MANDA PRODUCCION.
             *
             * `price` sale del PIVOTE, y un pivote NO hereda los moldes del
             * modelo: `ProductBusiness::$casts` declara `'price' =>
             * 'decimal:2'`, pero `$product->pivot->price` llega crudo de PDO,
             * y eso es CADENA en MySQL y NUMERO en SQLite. La suite afirmaba
             * 9500 y la app instalada recibia "9500.00".
             *
             * Se fija en cadena, y no en numero, para que la respuesta de
             * produccion no se mueva. Lo unico que cambia es el caso del
             * candado --`0` pasa a `"0.00"`--, y su unico consumidor es
             * `BusinessDetailScreen.js:382`, que hace `elegida.price > 0`:
             * `"0.00" > 0` sigue siendo falso. Fijarlo en numero seria mas
             * coherente con el `(float)` de `min_qty` de aqui abajo, pero
             * cambiaria el caso comun en los telefonos ya instalados.
             */
            'price' => number_format(
                (float) ($estaAfiliado ? ($product->pivot->price ?? 0) : 0), 2, '.', '',
            ),
            // Como se vende: por unidad o al peso. Ver arriba.
            'sale_unit' => $product->pivot->sale_unit ?? 'unidad',
            'min_qty' => $product->pivot->min_qty !== null ? (float) $product->pivot->min_qty : null,
            'step_qty' => $product->pivot->step_qty !== null ? (float) $product->pivot->step_qty : null,
            // Si se puede pedir por plata: «$1.500 de queso».
            'por_valor' => (bool) ($product->pivot->por_valor ?? false),
            /*
                 * `null` cuando no hay ninguna. La app pinta la etiqueta
                 * solo si viene algo.
                 *
                 * Y NUNCA sobre lo que se vende al peso: el motor de
                 * descuentos excluye esas lineas --un «lleva 3, paga 2»
                 * sobre 1,18 kg no esta definido-- asi que la etiqueta
                 * prometeria una rebaja que el carrito no va a aplicar.
                 * Es peor que no ensenar nada: el cliente llega al total
                 * esperando otro numero.
                 */
            /*
             * LAS PRESENTACIONES Y LA FICHA DEL OFICIO.
             *
             * `group` y `variante` los mandaba el listado viejo, y al pasar
             * el catálogo a esta puerta se quedaron fuera: la app recibía
             * «ACEITE RIQUISIMO 250ML», «500ML» y «900ML» como tres productos
             * sueltos aunque la base los tiene en un mismo grupo. Con `group`
             * la ficha los junta en una tarjeta y se elige el tamaño.
             *
             * `detalles`: lo que el oficio pregunta aparte (ver
             * `ProductTypeSchema::detallesParaElComprador`).
             */
            'brand' => $product->brand,
            'variante' => $product->variante,
            'group' => $product->group ? [
                'group_id' => (int) $product->group->group_id,
                'name' => $product->group->name,
                'image' => $product->group->image,
            ] : null,
            'detalles' => ProductTypeSchema::detallesParaElComprador($this->tipoDeNegocio, $product),
            'promocion' => UnidadDeVenta::esPeso($product->pivot->sale_unit ?? 'unidad')
                ? null
                : $promoDe((int) $product->products_id),
        ];
    }
}
