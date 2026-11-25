<?php

declare(strict_types=1);

namespace App\Http\Resources\Producto;

use App\Support\CategoriaDelNegocio;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * UN PRODUCTO EN EL CATALOGO PROPIO DE LA TIENDA. Dieciocho claves.
 *
 * Era el cierre `->map()` de `ProductosDelNegocioController::index()`: la
 * lista de trabajo del tendero, no la vitrina del comprador.
 *
 * LAS CUATRO CLAVES DEL PESO SON LA CONFIGURACION, no la medida. Acá el
 * tendero decide COMO vende cada referencia:
 *
 *   sale_unit     `unidad`, `kg`, `libra` o `gramo`
 *   stock_weight  las existencias cuando no se vende por unidad
 *   min_qty       el minimo vendible
 *   step_qty      el escalon de las porciones
 *
 * No se confunda con las cinco del RENGLON de un pedido
 * --`RenglonDelPedidoResource`--, que son lo que se pidio y lo que se peso de
 * verdad. Mismo asunto, dos momentos: aqui se configura, alli se registra.
 *
 * Y `price` NO CAMBIA DE SIGNIFICADO SEGUN LA FILA, aunque lo parezca: con
 * una unidad de peso es el precio POR ESA MEDIDA, y `amount` se queda para lo
 * que se vende por unidad. Es la decision que evito duplicar la columna de
 * precio, y esta escrita en el plan: dos columnas de precio seria repetir el
 * fallo de la tarifa duplicada que este repositorio ya documenta.
 *
 * `sin_precio` sale calculado y no es redundante con `price`: los productos
 * que llegan por copia de otra tienda nacen en cero a proposito --se copia
 * QUE vende, no A CUANTO-- y el panel tiene que poder senalarlos, porque son
 * justo la lista de trabajo. Al comprador no se le ofrecen.
 *
 * `compartido` es cuantas tiendas MAS lo venden. Cero significa que es solo
 * suyo y los campos compartidos se pueden editar; con uno o mas, lo que
 * escriba se lo escribe a las demas.
 *
 * `expiration_date` es del LOTE de esta tienda. Antes colgaba del producto
 * compartido, asi que la fecha que escribiera una tienda se la escribia a
 * todas: el atun de la esquina vencia el mismo dia que el de tres cuadras.
 */
class ProductoDeLaTiendaResource extends JsonResource
{
    public function __construct(
        object $productoDeLaTienda,
        private readonly int $otrasTiendas,
    ) {
        parent::__construct($productoDeLaTienda);
    }

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $pb = $this->resource;
        $p = $pb->product;

        return [
            'products_id' => (int) $p->products_id,
            'name' => $p->name,
            'barcode' => $p->barcode,
            'brand' => $p->brand,
            'description' => $p->description,
            'image' => $p->image,
            // La de este negocio si la tiene; si no, la del producto.
            'category' => CategoriaDelNegocio::de($pb->category_id ?? null, $p->category_id),
            'state' => (bool) $p->state,
            'price' => (float) $pb->price,
            // Lo que le cuesta a la tienda: sólo lo ve ella, en su catálogo.
            'costo' => $pb->costo !== null ? (float) $pb->costo : null,
            'amount' => (int) $pb->amount,
            /*
             * Como lo vende ESTA tienda. Con una unidad de peso, `price` es el
             * precio por esa medida y las existencias estan en `stock_weight`:
             * `amount` se queda para lo que se vende por unidad.
             */
            'sale_unit' => $pb->sale_unit ?? 'unidad',
            'stock_weight' => (float) $pb->stock_weight,
            'min_qty' => $pb->min_qty !== null ? (float) $pb->min_qty : null,
            'step_qty' => $pb->step_qty !== null ? (float) $pb->step_qty : null,
            // Si se puede pedir por plata: «$1.500 de queso».
            'por_valor' => (bool) ($pb->por_valor ?? false),
            // Sin precio no se ofrece al comprador: ver la cabecera.
            'sin_precio' => (float) $pb->price <= 0,
            // El vencimiento es del LOTE de esta tienda: ver la cabecera.
            'expiration_date' => $pb->expiration_date,
            'qualification' => $pb->qualification !== null ? (float) $pb->qualification : null,
            // Cuantas tiendas MAS lo venden.
            'compartido' => $this->otrasTiendas,
        ];
    }
}
