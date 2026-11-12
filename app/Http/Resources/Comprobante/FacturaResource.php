<?php

declare(strict_types=1);

namespace App\Http\Resources\Comprobante;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Arr;

/**
 * UN COMPROBANTE EN EL PANEL DE ADMINISTRACION.
 *
 * `FacturasApiController::anular()` devolvia `'invoice' => $f` crudo. Son
 * veinticuatro columnas, con `snapshot` —la copia congelada del pedido— y los
 * campos de la anulacion.
 *
 * SE CONSERVAN LAS VEINTICUATRO, a proposito. Esta ruta vive detras de
 * `modulo:facturas`, asi que no es una fuga: la ve quien administra. Lo que
 * cambia es que la lista esta escrita y medida, y que una columna nueva en
 * `invoice` ya no se publica sin que nadie lo decida.
 *
 * No se confunda con `ComprobanteResource`, que es el comprobante que ve el
 * COMPRADOR en la app y lleva mucho menos.
 */
class FacturaResource extends JsonResource
{
    /**
     * Lo que esta respuesta publica HOY, medido contra una fila real.
     *
     * VEINTICUATRO claves, y se conservan las veinticuatro: es el panel de administracion, detras de `modulo:facturas`, y la pantalla de anulacion lee el comprobante completo.
     */
    private const CAMPOS = [
        'invoice_id',
        'orderSales_id',
        'busines_id',
        'buyer_id',
        'payments_id',
        'payment_provider',
        'payment_reference',
        'invoice_number',
        'invoice_date',
        'subtotal',
        'descuento',
        'domicilio',
        'domiciliary_fee',
        'iva',
        'total',
        'currency',
        'snapshot',
        'state',
        'void_reason',
        'voided_at',
        'voided_by',
        'notes',
        'created_at',
        'updated_at',
    ];

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return Arr::only($this->resource->toArray(), self::CAMPOS);
    }
}
