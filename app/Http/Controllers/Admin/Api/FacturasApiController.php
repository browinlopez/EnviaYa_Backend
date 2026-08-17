<?php

namespace App\Http\Controllers\Admin\Api;

use App\Http\Controllers\Controller;
use App\Models\Invoice\Invoice;
use App\Services\FacturaService;
use App\Support\ListadoPaginado;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * COMPROBANTES DE ENTREGA
 *
 * Se emiten solos al entregar un pedido, así que acá no hay alta: solo consulta,
 * descarga y anulación. Es una diferencia de fondo con el resto del panel y por
 * eso la pantalla no tiene botón de "nuevo".
 */
class FacturasApiController extends Controller
{
    public function index(Request $request)
    {
        $q = DB::table('invoices as i')
            ->leftJoin('business as b', 'b.busines_id', '=', 'i.busines_id')
            ->leftJoin('buyer as by', 'by.buyer_id', '=', 'i.buyer_id')
            ->leftJoin('user as bu', 'bu.user_id', '=', 'by.user_id')
            ->select([
                'i.invoice_id', 'i.invoice_number', 'i.invoice_date',
                'i.orderSales_id', 'i.subtotal', 'i.descuento', 'i.domicilio',
                'i.total', 'i.currency', 'i.state', 'i.void_reason', 'i.voided_at',
                'i.payment_provider', 'i.payment_reference',
                'i.busines_id', 'b.name as business_name', 'b.logo as business_logo',
                'bu.name as buyer_name',
            ]);

        if ($negocio = (int) $request->query('business_id')) {
            $q->where('i.busines_id', $negocio);
        }

        if ($dias = (int) $request->query('days')) {
            $q->where('i.invoice_date', '>=', Carbon::now()->subDays($dias));
        }

        match ($request->query('estado')) {
            'emitidas' => $q->where('i.state', FacturaService::EMITIDA),
            'anuladas' => $q->where('i.state', FacturaService::ANULADA),
            default    => null,
        };

        return response()->json(ListadoPaginado::responder(
            $request,
            $q,
            buscables: ['i.invoice_number', 'b.name', 'bu.name', 'i.orderSales_id'],
            ordenables: [
                'invoice_number' => 'i.invoice_number',
                'invoice_date'   => 'i.invoice_date',
                'total'          => 'i.total',
                'business_name'  => 'b.name',
                'state'          => 'i.state',
            ],
            ordenPorDefecto: 'invoice_date',
            resumen: fn ($f) => $this->resumen($f),
        ));
    }

    /**
     * Indicadores sobre TODO lo filtrado.
     *
     * Lo anulado NO suma al facturado: es justo lo que un comprobante anulado
     * significa. Contarlo dejaría la caja del panel por encima de la real.
     */
    private function resumen($q): array
    {
        $emitida = FacturaService::EMITIDA;

        $r = ListadoPaginado::soloAgregados($q, "
            COUNT(*) as total,
            SUM(CASE WHEN i.state = {$emitida} THEN 1 ELSE 0 END) as emitidas,
            SUM(CASE WHEN i.state <> {$emitida} THEN 1 ELSE 0 END) as anuladas,
            COALESCE(SUM(CASE WHEN i.state = {$emitida} THEN i.total ELSE 0 END), 0) as facturado,
            COALESCE(SUM(CASE WHEN i.state = {$emitida} THEN i.domicilio ELSE 0 END), 0) as domicilios,
            COALESCE(SUM(CASE WHEN i.state = {$emitida} THEN i.descuento ELSE 0 END), 0) as descuentos
        ");

        $emitidas = (int) ($r->emitidas ?? 0);

        return [
            'total'      => (int) ($r->total ?? 0),
            'emitidas'   => $emitidas,
            'anuladas'   => (int) ($r->anuladas ?? 0),
            'facturado'  => round((float) ($r->facturado ?? 0), 2),
            'domicilios' => round((float) ($r->domicilios ?? 0), 2),
            'descuentos' => round((float) ($r->descuentos ?? 0), 2),
            'promedio'   => $emitidas > 0
                ? round((float) $r->facturado / $emitidas, 2)
                : 0,
        ];
    }

    public function show($id)
    {
        $f = Invoice::find($id);
        abort_if(!$f, 404, 'El comprobante no existe.');

        // La foto guardada ya trae negocio, comprador y renglones: no hace falta
        // consultar nada vivo, que es justo el punto de haberla guardado.
        return response()->json($f);
    }

    /**
     * El PDF.
     *
     * Se genera al pedirlo y no se archiva: los datos ya son inmutables, así que
     * el documento es reproducible. Guardarlo costaría espacio y añadiría un
     * modo de fallo — comprobante que existe con PDF que no subió.
     */
    public function pdf($id, FacturaService $facturas)
    {
        $f = Invoice::find($id);
        abort_if(!$f, 404, 'El comprobante no existe.');

        return response($facturas->pdf($f), 200, [
            'Content-Type' => 'application/pdf',
            // `inline` y no `attachment`: el panel lo abre en un visor y desde
            // ahí se descarga. Forzar la descarga obliga a abrir el archivo
            // desde el sistema para ver si era el correcto.
            'Content-Disposition' => 'inline; filename="' . $facturas->nombreArchivo($f) . '"',
        ]);
    }

    public function anular(Request $request, $id, FacturaService $facturas)
    {
        $datos = $request->validate([
            // El motivo es OBLIGATORIO. Una anulación sin explicación es un
            // agujero en la contabilidad que nadie puede reconstruir después.
            'motivo' => 'required|string|min:5|max:255',
        ]);

        $f = Invoice::find($id);
        abort_if(!$f, 404, 'El comprobante no existe.');

        try {
            $f = $facturas->anular($f, $datos['motivo'], $request->user()->user_id ?? null);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'message' => 'Comprobante anulado. Conserva su número y queda el motivo registrado.',
            'invoice' => $f,
        ]);
    }
}
