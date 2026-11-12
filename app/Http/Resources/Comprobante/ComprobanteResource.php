<?php

declare(strict_types=1);

namespace App\Http\Resources\Comprobante;

use App\Models\Invoice\Invoice;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * EL COMPROBANTE DE UN PEDIDO ENTREGADO.
 *
 * Era el `response()->json` de `ComprobanteDelPedidoController::responder()`,
 * movido aqui sin tocar un campo. Lo sirven las dos puertas del comprobante
 * —la del comprador (`GET /orders/{id}/comprobante`) y la de la tienda
 * (`GET /negocio/pedidos/{id}/comprobante`)— y las dos pasaban ya por el
 * mismo metodo, asi que aqui no habia divergencia que descubrir.
 *
 * `snapshot` ES EL PUNTO DE TODA LA CLASE, y por eso va tal cual: es la foto
 * guardada del pedido —negocio, comprador, entrega y renglones— y nada de eso
 * se consulta vivo. Un comprobante que recalculara al leerse cambiaria si
 * cambia un precio, y entonces no seria un comprobante.
 *
 * EL ENLACE DEL PDF SE RECIBE, NO SE CALCULA AQUI. Es una URL firmada con
 * caducidad, y firmarla pide `URL::temporarySignedRoute` mas la base de
 * `config('app.url')`: eso es una decision del controlador —ademas de tener
 * su propia trampa documentada, la firma RELATIVA para que el proxy del
 * despliegue no la invalide— y meterla en un Resource mezclaria la forma de
 * la respuesta con como se construye un enlace seguro.
 */
class ComprobanteResource extends JsonResource
{
    public function __construct(
        Invoice $comprobante,
        private readonly string $enlacePdf,
    ) {
        parent::__construct($comprobante);
    }

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $comprobante = $this->resource;

        return [
            'invoice_id' => $comprobante->invoice_id,
            'invoice_number' => $comprobante->invoice_number,
            'invoice_date' => $comprobante->invoice_date,
            'order_id' => $comprobante->orderSales_id,
            'subtotal' => $comprobante->subtotal,
            'descuento' => $comprobante->descuento,
            'domicilio' => $comprobante->domicilio,
            'iva' => $comprobante->iva,
            'total' => $comprobante->total,
            'currency' => $comprobante->currency,
            'anulado' => $comprobante->estaAnulada(),
            // La foto guardada: negocio, comprador, entrega y renglones. Nada
            // de esto se consulta vivo, que es el punto de haberla guardado.
            'snapshot' => $comprobante->snapshot ?? [],
            'pdf_url' => $this->enlacePdf,
        ];
    }
}
