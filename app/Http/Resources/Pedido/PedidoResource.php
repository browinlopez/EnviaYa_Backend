<?php

declare(strict_types=1);

namespace App\Http\Resources\Pedido;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * UN PEDIDO, LA FORMA QUE DEVUELVEN LAS ESCRITURAS.
 *
 * Es la forma que estaba en `OrdersSales::toApi()`, movida aqui sin tocar un
 * campo. La sirven crear un pedido, cancelarlo, corregirlo y registrar su
 * pesaje; la lista de la app usa `PedidoDeLaAppResource`, que es otra forma
 * y la cabecera de esa clase dice en que se diferencian.
 *
 * POR QUE SALE DEL MODELO. Mientras la forma vive en `toApi()`, el modelo
 * tiene dos trabajos —ser la fila y ser la respuesta— y el segundo viaja
 * gratis a cualquier sitio que serialice el modelo. Esa es la via por la que
 * un campo nuevo en la tabla aparece en una respuesta sin que nadie lo
 * decida.
 *
 * Lo unico que cambio al moverlo: `$this->` pasa a `$pedido->`, porque dentro
 * de un Resource `$this` es el Resource. La sustitucion se hizo con un
 * reemplazo mecanico sobre el texto extraido del metodo, no transcribiendo a
 * mano: son setenta lineas de nombres de campo que leen las apps publicadas
 * en las dos tiendas, y la vista no es el instrumento para eso.
 */
class PedidoResource extends JsonResource
{
    /**
     * ─────────────────────────────────────────────────────────────────────
     * LO QUE HAY QUE TRAER CARGADO ANTES DE PINTAR ESTO.
     *
     * No es higiene: con la carga perezosa desactivada, pintar un pedido sin
     * estas relaciones LANZA. Y lanza DESPUES de haber hecho el trabajo, asi
     * que la operacion sale bien y la respuesta es un 500.
     *
     * Paso de verdad: cancelando un pedido desde la app del tendero, el
     * pedido quedaba cancelado y la pantalla decia «No se pudo cancelar»
     * con «Attempted to lazy load [product] on model [OrdersSalesDetail]»
     * encima. Se arreglo `product` y a la siguiente salto `category`.
     *
     * Por eso la lista vive AQUI y no en cada controlador: quien pinta es
     * quien sabe que lee, y asi anadir un campo al `toArray()` no obliga a
     * acordarse de tres sitios. Es el mismo patron que `PedidoDeLaAppResource`.
     *
     * `.category` Y NO SOLO `.product`: `toArray()` lee
     * `$d->product->category`, que es un salto mas.
     * ─────────────────────────────────────────────────────────────────────
     *
     * @return list<string>
     */
    public static function relaciones(): array
    {
        return [
            'buyer.user',
            'business.municipality',
            /*
             * La direccion llega con sus tres niveles porque `toArray()` los
             * lee: municipio, departamento y --via municipio-- el pais.
             */
            'address.municipality.department.country',
            'address.department',
            'domiciliary.user',
            'details.product.category',
            'payments',
            'promotions',
        ];
    }

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $pedido = $this->resource;

        return [
            'order_id' => $pedido->orderSales_id,
            'buyer_id' => $pedido->buyer_id,
            'busines_id' => $pedido->busines_id,
            'total' => $pedido->total,
            // Con cuánto paga y cuánto cambio llevarle. Solo en efectivo.
            'cash_tendered' => $pedido->cash_tendered,
            'change_due' => $pedido->change_due,
            // 0 no aplica · 1 por pesar · 2 pesado. Es una dimension aparte
            // del estado del pedido, no un estado nuevo.
            'weighing_state' => (int) $pedido->weighing_state,
            'sale_date' => $pedido->sale_date,
            'delivery_date' => $pedido->delivery_date,
            'state' => $pedido->state,
            'is_scheduled' => $pedido->is_scheduled,
            'has_review' => $pedido->has_review,
            'dispatched_at' => $pedido->dispatched_at,
            // Compromiso y cumplimiento. `promised_minutes` es lo que el reloj
            // del seguimiento tiene que contar: antes la app usaba un 20 fijo
            // porque leía un campo que el servidor nunca mandó.
            'promised_minutes' => $pedido->promised_minutes,
            'delivery_minutes' => $pedido->delivery_minutes,
            'on_time' => $pedido->on_time,
            'delay_minutes' => $pedido->delay_minutes,
            'domiciliary' => $pedido->domiciliary ? [
                'domiciliary_id' => $pedido->domiciliary->domiciliary_id,
                'user_id' => $pedido->domiciliary->user->user_id ?? null,
                'name' => $pedido->domiciliary->user->name ?? null,
                'phone' => $pedido->domiciliary->user->phone ?? null,
                'qualification' => $pedido->domiciliary->qualification,
            ] : null,
            'business' => [
                'business_id' => $pedido->business->busines_id ?? null,
                'name' => $pedido->business->name ?? null,
                'address' => $pedido->business->address ?? null,
                'latitude' => $pedido->business->latitude ? (float) $pedido->business->latitude : null,
                'longitude' => $pedido->business->longitude ? (float) $pedido->business->longitude : null,
                'phone' => $pedido->business->phone ?? null,
                // La ciudad sale de `municipality`: ver NegocioDelPedidoResource.
                'city' => $pedido->business->municipality?->name,
                'state' => $pedido->business->state ?? null,
                'logo' => $pedido->business->logo ?? null,
            ],
            'delivery_address' => $pedido->address ? [
                'address_id' => $pedido->address->address_id,
                'address' => $pedido->address->address ?? null,
                'alias' => $pedido->address->alias?->name,
                'municipality' => $pedido->address->municipality?->name,
                'department' => $pedido->address->department?->name,
                /*
                 * POR LA CADENA y no por `UserAddress::country()`, que esta
                 * marcada `@deprecated Devuelve null siempre` en el propio
                 * modelo: es un `hasOneThrough` y hacen falta TRES saltos.
                 * 13 de noviembre de 2026.
                 *
                 * Cuesta UNA consulta mas, y aqui se paga sin discusion: esta
                 * forma responde a UN pedido --no a una lista-- asi que es una
                 * consulta por peticion. En la lista del domiciliario NO se
                 * paga, y el motivo esta escrito alli.
                 */
                'country' => $pedido->address->municipality?->department?->country?->name,
                'latitude' => $pedido->address->latitude ? (float) $pedido->address->latitude : null,
                'longitude' => $pedido->address->longitude ? (float) $pedido->address->longitude : null,
            ] : null,
            'details' => $pedido->details->map(fn ($d) => [
                // Por donde se pesa una linea; ver `PesajeDelPedidoController`.
                'orderDet_id' => $d->orderDet_id,
                'product_id' => $d->product->products_id ?? null,
                'name' => $d->product->name ?? null,
                'description' => $d->product->description ?? null,
                'category' => $d->product->category?->name ?? null,
                'image' => $d->product->image ?? null,
                'amount' => $d->amount,
                'unit_price' => $d->unit_price,
                // Lo que se vende al peso: `amount` vale 1 en esas lineas y la
                // cantidad de verdad va aca. Ver la migracion del peso.
                'sale_unit' => $d->sale_unit,
                'pedido_por' => $d->pedido_por,
                'qty_requested' => $d->qty_requested,
                'qty_final' => $d->qty_final,
                'line_total' => $d->line_total,
            ])->toArray(),
            'promotions' => $pedido->promotions ?? [],
            'payments' => $pedido->payments ?? null,
        ];
    }
}
