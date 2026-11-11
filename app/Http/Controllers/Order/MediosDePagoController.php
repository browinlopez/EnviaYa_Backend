<?php

namespace App\Http\Controllers\Order;

use App\Http\Controllers\Concerns\ComprobarPertenencia;
use App\Http\Controllers\Controller;
use App\Models\Payment\PaymentMethods;
use App\Services\PagoEnLinea;

class MediosDePagoController extends Controller
{
    use ComprobarPertenencia;

    // Obtener métodos de pago
    /**
     * Solo los medios que el sistema SABE COBRAR.
     *
     * La tabla tiene seis y solo tres se cobran: efectivo en la puerta, y
     * tarjeta de credito y QR por pasarela. Los otros tres —tarjeta debito,
     * transferencia bancaria y pago movil— se ofrecian igual, y elegir uno
     * dejaba el pedido en `pending_cash`: nadie abria un cobro, el comprador
     * creia que habia transferido, y el domiciliario llegaba esperando
     * efectivo. Nadie habia acordado nada.
     *
     * Se filtra POR CODIGO y no apagandolos en la tabla: si manana alguien
     * vuelve a encender la fila desde el panel, el hueco no reaparece. La
     * lista de lo que se cobra vive en un solo sitio, `PagoEnLinea`, que es la
     * misma que decide si se abre el cobro.
     */
    public function paymentMethods()
    {
        $methods = PaymentMethods::with('forms')
            ->where('state', 1)
            ->whereIn('methods_id', PagoEnLinea::metodosQueSeCobran())
            ->get();

        return response()->json($methods);
    }
}
