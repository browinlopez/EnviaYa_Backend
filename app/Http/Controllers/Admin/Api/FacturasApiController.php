<?php

namespace App\Http\Controllers\Admin\Api;

use App\Http\Controllers\Controller;
use App\Models\Invoice\Invoice;
use App\Services\FacturaService;
use App\Support\ListadoPaginado;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
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
    /**
     * Cuántos comprobantes atrasados se emiten como máximo por petición.
     *
     * Es una petición web, no un comando: recuperar cinco mil entregas de golpe
     * agotaría el tiempo del servidor y quien lo pidió no sabría cuántas
     * quedaron hechas. Con tope, la respuesta dice qué se emitió y qué falta, y
     * volver a pulsar sigue por donde iba.
     */
    private const TOPE_EMISION = 200;

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

        $respuesta = ListadoPaginado::responder(
            $request,
            $q,
            buscables: ['i.invoice_number', 'b.name', 'bu.name', 'i.orderSales_id'],
            ordenables: [
                'invoice_number' => 'i.invoice_number',
                'invoice_date'   => 'i.invoice_date',
                'total'          => 'i.total',
                'business_name'  => 'b.name',
                'buyer_name'     => 'bu.name',
                'state'          => 'i.state',
            ],
            ordenPorDefecto: 'invoice_date',
            resumen: fn ($f) => $this->resumen($f),
        );

        /*
         * El hueco se cuenta aparte y se añade al resumen.
         *
         * No puede salir del `resumen`, que recibe la consulta de FACTURAS: lo
         * que se busca es justo lo que NO está ahí. Y es el dato más accionable
         * de la pantalla — una entrega sin comprobante no se nota mirando una
         * lista de comprobantes, porque lo que falta no aparece en ella.
         */
        if (is_array($respuesta['summary'] ?? null)) {
            $respuesta['summary'] += $this->huecoDeEmision($request);
        }

        return response()->json($respuesta);
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

    /**
     * Pedidos entregados a los que les falta el comprobante.
     *
     * La usan el contador de la pantalla y el botón que los emite, para que
     * ambos hablen exactamente del mismo conjunto.
     *
     * Respeta el negocio y el rango de la barra, pero NO el filtro de
     * emitidas/anuladas: ese filtra comprobantes y acá se buscan pedidos.
     */
    private function entregasSinComprobante(Request $request): Builder
    {
        $q = DB::table('orderssales as o')
            ->where('o.state', 4)
            ->whereNotExists(fn ($sub) => $sub
                ->from('invoices as i')
                ->whereColumn('i.orderSales_id', 'o.orderSales_id'));

        if ($negocio = (int) $request->query('business_id')) {
            $q->where('o.busines_id', $negocio);
        }

        if ($dias = (int) $request->query('days')) {
            // Contra la fecha de ENTREGA, que es la que llevaría el comprobante.
            $q->where('o.delivery_date', '>=', Carbon::now()->subDays($dias));
        }

        return $q;
    }

    /**
     * Entregas que NO dejaron comprobante.
     *
     * La emisión al entregar va dentro de un try —una entrega no puede fallar
     * por un problema de papeleo—, así que si alguna vez falla, el pedido queda
     * entregado y sin registro. Eso es plata cobrada sin constancia, y es
     * invisible: no sale en el listado de comprobantes porque justamente no
     * tiene uno.
     */
    private function huecoDeEmision(Request $request): array
    {
        $r = $this->entregasSinComprobante($request)
            ->selectRaw('COUNT(*) as cuantas, COALESCE(SUM(o.total), 0) as valor')
            ->first();

        return [
            'sin_comprobante'       => (int) ($r->cuantas ?? 0),
            'sin_comprobante_valor' => round((float) ($r->valor ?? 0), 2),
        ];
    }

    /**
     * Emite lo atrasado desde el panel.
     *
     * Existe el comando `facturas:emitir`, programado a diario, pero quien ve el
     * hueco en la pantalla es contabilidad y no tiene consola. Sin esto, la
     * única salida es esperar al día siguiente o pedirle a alguien que entre al
     * servidor.
     *
     * No crea nada nuevo: `emitirPara` es idempotente y solo actúa sobre pedidos
     * ya entregados, así que pulsarlo dos veces no puede duplicar un
     * comprobante ni inventar uno sin entrega detrás.
     */
    public function emitirPendientes(Request $request, FacturaService $facturas)
    {
        /*
         * Con los MISMOS filtros con los que se contó.
         *
         * Si el botón emitiera todo lo pendiente de la plataforma mientras la
         * pantalla dice "3 entregas sin comprobante del negocio X", el mensaje
         * de vuelta hablaría de un número que no se corresponde con nada de lo
         * que se estaba viendo.
         */
        $q = $this->entregasSinComprobante($request)
            // Del más viejo al más nuevo: así el consecutivo sigue el orden real
            // de las entregas y no el orden en que se pulsó el botón.
            ->orderBy('o.delivery_date')
            ->orderBy('o.orderSales_id');

        $pendientes = (clone $q)->count();

        if ($pendientes === 0) {
            return response()->json([
                'message'  => 'Todas las entregas ya tienen su comprobante.',
                'emitidas' => 0,
                'faltan'   => 0,
            ]);
        }

        $emitidas = 0;
        $fallos = 0;

        foreach ($q->limit(self::TOPE_EMISION)->pluck('o.orderSales_id') as $id) {
            try {
                if ($facturas->emitirPara((int) $id)) {
                    $emitidas++;
                }
            } catch (\Throwable $e) {
                // Uno que falla no detiene a los demás: emitir 84 de 85 y decir
                // que falta uno es mejor que no emitir ninguno.
                $fallos++;
                Log::warning("No se pudo emitir el comprobante del pedido {$id}", [
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $faltan = max(0, $pendientes - $emitidas);

        $mensaje = $emitidas === 1
            ? 'Se emitió 1 comprobante.'
            : "Se emitieron {$emitidas} comprobantes.";

        if ($faltan > 0) {
            $mensaje .= $fallos > 0
                ? " Quedan {$faltan}: {$fallos} dieron error y están en el registro."
                : " Quedan {$faltan}; vuelve a pulsar para seguir.";
        }

        return response()->json([
            'message'  => $mensaje,
            'emitidas' => $emitidas,
            'faltan'   => $faltan,
        ]);
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

    /**
     * Anula VARIOS comprobantes con el mismo motivo.
     *
     * Existe porque el error que lleva a anular casi nunca es de uno solo: se
     * facturó dos veces el corte de un día, o un negocio subió mal sus precios
     * toda una tarde. De a uno son cuatro clics por comprobante, y el motivo
     * escrito quince veces acaba siendo "error" — que no explica nada.
     *
     * TOPE de 50. Anular es destructivo para la contabilidad, y una acción
     * destructiva sin techo convierte un identificador de más en un desastre.
     *
     * Los que ya estaban anulados NO son un error: se saltan y se dicen. Un lote
     * que falla entero porque uno de los cincuenta ya estaba anulado obligaría a
     * repetirlo quitando ese, con el riesgo de anular de más al rehacer la
     * selección.
     */
    public function anularLote(Request $request, FacturaService $facturas)
    {
        $datos = $request->validate([
            'ids'    => 'required|array|min:1|max:50',
            'ids.*'  => 'integer',
            'motivo' => 'required|string|min:5|max:255',
        ]);

        $usuario = $request->user()->user_id ?? null;

        $anulados = 0;
        $saltados = 0;

        foreach (Invoice::whereIn('invoice_id', $datos['ids'])->get() as $f) {
            if ($f->estaAnulada()) {
                $saltados++;
                continue;
            }

            $facturas->anular($f, $datos['motivo'], $usuario);
            $anulados++;
        }

        $mensaje = $anulados === 1
            ? 'Se anuló 1 comprobante.'
            : "Se anularon {$anulados} comprobantes.";

        if ($saltados > 0) {
            $mensaje .= " {$saltados} ya estaban anulados y se dejaron como estaban.";
        }

        return response()->json([
            'message' => $mensaje,
            'voided'  => $anulados,
            'skipped' => $saltados,
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
