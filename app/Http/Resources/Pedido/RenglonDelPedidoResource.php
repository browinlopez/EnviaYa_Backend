<?php

declare(strict_types=1);

namespace App\Http\Resources\Pedido;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * UN RENGLÓN DE UN PEDIDO: qué producto, cuánto y a cómo.
 *
 * Esta forma estaba DOS VECES, y las dos copias eran idénticas línea por
 * línea, incluidos los moldes y los `?->`:
 *
 *   PedidoDeLaAppResource            la lista del domiciliario
 *   ConsultaDePedidosController      el historial del comprador
 *
 * Trece claves, y las CINCO ÚLTIMAS son las de la venta por peso:
 * `sale_unit`, `pedido_por`, `qty_requested`, `qty_final` y `line_total`. Ahí
 * está el riesgo concreto de tener dos copias: el peso es lo más nuevo del
 * sistema y lo que más va a cambiar. Si alguien añade un campo a una copia y
 * no a la otra, el historial del comprador y la lista del domiciliario dicen
 * cosas distintas DEL MISMO PEDIDO, y eso no rompe nada: simplemente, en una
 * pantalla falta un dato que en la otra está.
 *
 * `product_id` sale de `product->products_id` y no de la columna del renglón,
 * que es como estaba. `category` va con `?->` porque un producto puede no
 * tener categoría y la app lo pinta vacío sin quejarse.
 *
 * EL RENGLÓN NO SABE SI SE PESÓ BIEN: `qty_requested` es lo que se pidió y
 * `qty_final` lo que pesó el tendero, las dos tal cual. Comparar las dos y
 * decidir si el desvío es aceptable es de `PesajeDelPedidoController`, que
 * tiene el tope configurado; un Resource que lo juzgara estaría poniendo
 * reglas de negocio en la frontera.
 */
class RenglonDelPedidoResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $detail = $this->resource;

        return [
            'orderDet_id' => $detail->orderDet_id,
            'product_id' => $detail->product->products_id,
            'name' => $detail->product->name,
            'description' => $detail->product->description,
            'category' => $detail->product->category?->name,
            'image' => $detail->product->image,
            'amount' => $detail->amount,
            'unit_price' => $detail->unit_price,
            'sale_unit' => $detail->sale_unit,
            'pedido_por' => $detail->pedido_por,
            'qty_requested' => $detail->qty_requested,
            'qty_final' => $detail->qty_final,
            'line_total' => $detail->line_total,
        ];
    }
}
