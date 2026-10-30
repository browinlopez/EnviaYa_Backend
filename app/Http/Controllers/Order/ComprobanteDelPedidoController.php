<?php

namespace App\Http\Controllers\Order;

use App\Http\Controllers\Controller;
use App\Models\Buyer\Buyer;
use App\Models\Invoice\Invoice;
use App\Models\Order\OrdersSales;
use App\Services\FacturaService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;

/**
 * EL COMPROBANTE DE UN PEDIDO, PARA QUIEN LO VIVIÓ
 *
 * El comprobante existe desde que `FacturaService` lo emite al entregar, pero
 * hasta ahora solo lo alcanzaba `/admin/invoices`, detrás del permiso de
 * facturas. O sea: el único papel que quedaba de una compra estaba donde ni el
 * comprador ni el tendero pueden entrar. Esto abre esa puerta a los dos, cada
 * uno al suyo y a ninguno más.
 *
 * QUIÉN PREGUNTA NO SE RECIBE EN EL CUERPO. El comprador sale de la sesión y
 * la tienda la deja puesta el middleware `negocio` tras comprobar la cadena de
 * propiedad. Recibir un identificador y creerle sería dejar que cualquiera
 * leyera el comprobante de otro, con su nombre, su teléfono y su dirección
 * dentro.
 */
class ComprobanteDelPedidoController extends Controller
{
    /**
     * Cuánto dura el enlace del PDF.
     *
     * El PDF lo abre un navegador, y un navegador no manda el token de la
     * sesión: por eso el enlace va firmado en vez de autenticado. La firma es
     * la llave, así que quien reenvíe el enlace reenvía el comprobante —que es
     * justo lo que hace quien lo manda por WhatsApp a su contador—. Media hora
     * alcanza de sobra para abrirlo y guardarlo, y es poco para que el enlace
     * siga sirviendo en un chat dentro de un mes.
     */
    private const MINUTOS_DEL_ENLACE = 30;

    /** El comprobante de MI pedido. */
    public function mio(Request $request, int $id, FacturaService $facturas)
    {
        $comprador = Buyer::where('user_id', $request->user()->user_id)->first();

        abort_if(!$comprador, 404, 'Esta cuenta no tiene compras.');

        $pedido = OrdersSales::find($id);

        /*
         * 404 y no 403 cuando el pedido es de otro. Un 403 confirmaría que ese
         * número de pedido existe, y con eso se puede recorrer la numeración
         * para saber cuánto vende la plataforma.
         */
        abort_if(!$pedido || (int) $pedido->buyer_id !== (int) $comprador->buyer_id, 404, 'Ese pedido no es tuyo.');

        return $this->responder($pedido, $facturas);
    }

    /** El comprobante de un pedido DE MI TIENDA. */
    public function deMiTienda(Request $request, int $id, FacturaService $facturas)
    {
        $negocio = (int) $request->attributes->get('busines_id');
        $pedido = OrdersSales::find($id);

        abort_if(!$pedido || (int) $pedido->busines_id !== $negocio, 404, 'Ese pedido no es de tu tienda.');

        return $this->responder($pedido, $facturas);
    }

    /**
     * El PDF, por enlace firmado.
     *
     * Va fuera de `auth:sanctum` a propósito: lo abre el navegador del teléfono
     * o una pestaña nueva del panel, y ninguno de los dos lleva el token. La
     * firma caduca y ampara un solo comprobante.
     */
    public function pdf(int $id, FacturaService $facturas)
    {
        $comprobante = Invoice::find($id);

        abort_if(!$comprobante, 404, 'El comprobante no existe.');

        return response($facturas->pdf($comprobante), 200, [
            'Content-Type' => 'application/pdf',
            // `inline`: que se vea en el visor del teléfono en vez de caer en
            // la carpeta de descargas sin que nadie sepa qué bajó.
            'Content-Disposition' => 'inline; filename="' . $facturas->nombreArchivo($comprobante) . '"',
        ]);
    }

    /**
     * Lo que se devuelve, sea quien sea el que pregunta.
     *
     * SE EMITE AL VUELO SI FALTA. `emitirPara()` es idempotente: devuelve el
     * que ya existe y solo crea cuando no hay ninguno. Hace falta porque el
     * disparador vive en el cambio de estado, y un pedido entregado antes de
     * que esto existiera —o uno cuya emisión falló por un corte— no tendría
     * comprobante nunca. Pedirlo lo repara en vez de enseñar un hueco.
     */
    private function responder(OrdersSales $pedido, FacturaService $facturas)
    {
        $comprobante = $facturas->emitirPara((int) $pedido->orderSales_id);

        /*
         * Sin comprobante solo puede ser una cosa: el pedido no está entregado.
         * Se dice con esas palabras, porque «no encontrado» en una pantalla que
         * muestra el pedido delante parece un error de la aplicación.
         */
        abort_if(!$comprobante, 404, 'El comprobante se emite cuando el pedido se entrega.');

        return response()->json([
            'invoice_id'     => $comprobante->invoice_id,
            'invoice_number' => $comprobante->invoice_number,
            'invoice_date'   => $comprobante->invoice_date,
            'order_id'       => $comprobante->orderSales_id,
            'subtotal'       => $comprobante->subtotal,
            'descuento'      => $comprobante->descuento,
            'domicilio'      => $comprobante->domicilio,
            'iva'            => $comprobante->iva,
            'total'          => $comprobante->total,
            'currency'       => $comprobante->currency,
            'anulado'        => $comprobante->estaAnulada(),
            // La foto guardada: negocio, comprador, entrega y renglones. Nada
            // de esto se consulta vivo, que es el punto de haberla guardado.
            'snapshot'       => $comprobante->snapshot ?? [],
            'pdf_url'        => $this->enlaceDelPdf($comprobante),
        ]);
    }

    /**
     * El enlace firmado del PDF.
     *
     * Se firma RELATIVO —ruta y parámetros, sin dominio ni esquema— y luego se
     * le pega la base. Detrás del proxy del despliegue, la petición puede
     * llegarle a Laravel como `http` aunque el mundo la haya pedido por
     * `https`: con una firma absoluta eso basta para que el enlace se dé por
     * falso y el comprobante no abra. La relativa no mira el dominio, así que
     * no depende de cómo venga reescrita la petición.
     */
    private function enlaceDelPdf(Invoice $comprobante): string
    {
        $ruta = URL::temporarySignedRoute(
            'comprobantes.pdf',
            now()->addMinutes(self::MINUTOS_DEL_ENLACE),
            ['id' => $comprobante->invoice_id],
            absolute: false,
        );

        return rtrim(config('app.url'), '/') . $ruta;
    }
}
