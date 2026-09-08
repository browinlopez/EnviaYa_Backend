<?php

namespace App\Http\Controllers\Admin\Api;

use App\Support\Consultas\AyudasDeAdmin;
use App\Services\Ajustes;
use App\Http\Controllers\Controller;
use App\Models\Area;
use App\Models\Conjunto\ComplexStaff;
use App\Models\Operacion\DomiciliaryDocument;
use App\Models\Reviews\BusinessReview;
use App\Models\Reviews\DomiciliaryReview;
use App\Models\User;
use App\Services\BusinessMediaService;
use App\Services\ContratoService;
use App\Services\MediaService;
use App\Services\VinculosDelRol;
use App\Support\ListadoPaginado;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class PedidosApiController extends Controller
{
    use AyudasDeAdmin;

    /**
     * Horas sin avanzar tras las que un pedido activo "pide atención".
     *
     * Vivía SOLO en el navegador, así que el filtro de atascados únicamente
     * veía lo que ya estaba cargado. Al pasar el filtrado al servidor tuvo que
     * venirse acá, que además es donde debía estar: la regla la aplica quien
     * consulta la base, y el panel se limita a repetirla en la insignia.
     *
     * Ya no es una constante: se ajusta desde el panel. Cuántas horas son
     * "demasiadas" depende de la ciudad y de la hora del día, y era una decisión
     * de operación que exigía un despliegue para cambiarse.
     */
    private function horasEstancado(): int
    {
        return (int) Ajustes::valor('operacion.horas_estancado');
    }

    /**
     * Pedidos, paginados en el servidor.
     *
     * Es el listado que crece sin techo: cada pedido que entra se queda para
     * siempre. Devolverlos todos funcionaba con nueve y se vuelve inusable con
     * cincuenta mil.
     *
     * Los FILTROS y los INDICADORES viven acá y no en el navegador. Es lo que
     * faltaba para poder paginar de verdad: mientras el panel sumaba lo que le
     * llegaba, servirle una página de 25 habría dejado "ingresos entregados"
     * mostrando el total de esas 25 como si fuera el del mes. En una pantalla
     * de dinero eso no es un dato incompleto, es un dato falso.
     */
    public function orders(Request $request)
    {
        $q = $this->ordenesBase();
        $this->filtrarPedidos($q, $request);

        return response()->json(ListadoPaginado::responder(
            $request,
            $q,
            buscables: ['bu.name', 'b.name', 'du.name', 'o.orderSales_id'],
            ordenables: [
                'orderSales_id' => 'o.orderSales_id',
                'sale_date'     => 'o.sale_date',
                'total'         => 'o.total',
                'state'         => 'o.state',
                'business_name' => 'b.name',
                'buyer_name'    => 'bu.name',
            ],
            ordenPorDefecto: 'sale_date',
            resumen: fn ($filtrada) => $this->resumenDePedidos($filtrada),
        ));
    }

    /**
     * Los filtros de la pantalla de pedidos.
     *
     * `atencion` es el único que no es una comparación directa: un pedido pide
     * atención si está despachado sin repartidor o si lleva demasiado sin
     * avanzar. Estaba calculado en el navegador y por eso el filtro solo veía
     * la página cargada; acá alcanza a todo el histórico, que es donde están
     * los que llevan días atascados.
     */
    private function filtrarPedidos($q, Request $request): void
    {
        if ($negocio = (int) $request->query('business_id')) {
            $q->where('o.busines_id', $negocio);
        }

        $domiciliario = $request->query('domiciliary_id');

        if ($domiciliario === 'sin') {
            $q->whereNull('o.domiciliary_id');
        } elseif ((int) $domiciliario) {
            $q->where('o.domiciliary_id', (int) $domiciliario);
        }

        if ($dias = (int) $request->query('days')) {
            $q->where('o.sale_date', '>=', Carbon::now()->subDays($dias));
        }

        $limite = Carbon::now()->subHours($this->horasEstancado());

        match ($request->query('state_group')) {
            'activos'    => $q->whereIn('o.state', self::ACTIVOS),
            'entregados' => $q->where('o.state', self::ENTREGADO),
            'sin_pago'   => $q->whereNotExists(fn ($sub) => $this->pagoAprobado($sub)),
            'atencion'   => $q->whereIn('o.state', self::ACTIVOS)
                ->where(fn ($sub) => $sub
                    ->where(fn ($x) => $x->where('o.state', 2)->whereNull('o.domiciliary_id'))
                    ->orWhere('o.sale_date', '<', $limite)),
            default => null,
        };
    }

    /** Indicadores de la pantalla, sobre TODO lo filtrado. */
    private function resumenDePedidos($q): array
    {
        $entregado = self::ENTREGADO;
        $activos   = implode(',', self::ACTIVOS);
        $limite    = Carbon::now()->subHours($this->horasEstancado())->toDateTimeString();

        $r = ListadoPaginado::soloAgregados($q, "
            COUNT(*) as total,
            SUM(CASE WHEN o.state IN ({$activos}) THEN 1 ELSE 0 END) as activas,
            SUM(CASE WHEN o.state IN ({$activos})
                      AND ((o.state = 2 AND o.domiciliary_id IS NULL)
                           OR o.sale_date < ?) THEN 1 ELSE 0 END) as atencion,
            COALESCE(SUM(CASE WHEN o.state = {$entregado} THEN o.total ELSE 0 END), 0) as ingresos,
            COALESCE(SUM(CASE WHEN o.state = {$entregado} THEN o.domicilio ELSE 0 END), 0) as domicilios,
            COALESCE(SUM(CASE WHEN o.state = {$entregado} THEN o.domiciliary_fee ELSE 0 END), 0) as comisiones
        ", [$limite]);

        return [
            'total'      => (int) ($r->total ?? 0),
            'activas'    => (int) ($r->activas ?? 0),
            'atencion'   => (int) ($r->atencion ?? 0),
            'ingresos'   => round((float) ($r->ingresos ?? 0), 2),
            'domicilios' => round((float) ($r->domicilios ?? 0), 2),
            'comisiones' => round((float) ($r->comisiones ?? 0), 2),
        ];
    }

    public function showOrder($id)
    {
        $orden = $this->ordenesBase()->where('o.orderSales_id', $id)->first();
        abort_if(!$orden, 404, 'El pedido no existe.');

        $orden->items = DB::table('orderssales_detail as od')
            ->leftJoin('products as p', 'p.products_id', '=', 'od.product_id')
            ->where('od.orderSales_id', $id)
            ->get(['od.orderDet_id', 'od.product_id', 'od.amount', 'od.unit_price', 'p.name']);

        return response()->json($orden);
    }

    /* ==================================================================
       DOMICILIARIOS
       ================================================================== */
}
