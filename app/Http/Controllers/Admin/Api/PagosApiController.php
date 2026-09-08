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

class PagosApiController extends Controller
{
    use AyudasDeAdmin;

    /** Cobros que no entraron. Se cuentan aparte de los pendientes. */
    private const PAGO_FALLIDO = ['rejected', 'failed', 'cancelled'];

    /** Valor de `payments.provider` para el cobro contra entrega. */
    private const EFECTIVO = 'cash';


    /** Pagos, paginados en el servidor: crecen al mismo ritmo que los pedidos. */
    public function payments(Request $request)
    {
        $q = DB::table('payments as p')
            ->leftJoin('orderssales as o', 'o.orderSales_id', '=', 'p.orderSales_id')
            ->leftJoin('buyer as by', 'by.buyer_id', '=', 'o.buyer_id')
            ->leftJoin('user as bu', 'bu.user_id', '=', 'by.user_id')
            // El negocio del pedido: un pago suelto no dice a qué tienda
            // corresponde la venta, que es justo lo que hace falta para
            // conciliar y para pagarle a cada uno.
            ->leftJoin('business as b', 'b.busines_id', '=', 'o.busines_id')
            ->leftJoin('payment_methods as pm', 'pm.methods_id', '=', 'p.methods_id')
            ->select([
                'p.payments_id', 'p.orderSales_id', 'p.provider', 'p.provider_payment_id',
                'p.amount', 'p.subtotal', 'p.total', 'p.domicilio', 'p.domiciliary_fee',
                'p.payment_status', 'p.status', 'p.payment_date', 'p.created_at',
                'bu.name as buyer_name', 'pm.name as method_name',
                'b.busines_id', 'b.name as business_name', 'b.logo as business_logo',
                // Estado del pedido al que pertenece el cobro: un pago
                // aprobado sobre un pedido que todavía va en camino no es
                // lo mismo que uno ya entregado.
                'o.state as order_state', 'o.delivery_date',
            ]);

        $this->filtrarPagos($q, $request);

        return response()->json(ListadoPaginado::responder(
            $request,
            $q,
            buscables: ['bu.name', 'b.name', 'p.provider_payment_id', 'p.orderSales_id'],
            ordenables: [
                'payments_id'   => 'p.payments_id',
                'amount'        => 'p.amount',
                'total'         => 'p.total',
                'payment_date'  => 'p.payment_date',
                'business_name' => 'b.name',
            ],
            ordenPorDefecto: 'payments_id',
            resumen: fn ($filtrada) => $this->resumenDePagos($filtrada),
        ));
    }

    private function filtrarPagos($q, Request $request): void
    {
        if ($negocio = (int) $request->query('business_id')) {
            $q->where('o.busines_id', $negocio);
        }

        if ($dias = (int) $request->query('days')) {
            $q->where('p.payment_date', '>=', Carbon::now()->subDays($dias));
        }

        // Efectivo contra pasarela. El corte es por `provider` y no por el
        // método declarado en el pedido: lo que importa para cuadrar la caja es
        // por dónde entró la plata, no por dónde se dijo que iba a entrar.
        match ($request->query('channel')) {
            'cash'    => $q->where('p.provider', self::EFECTIVO),
            'gateway' => $q->where(fn ($sub) => $sub
                ->where('p.provider', '!=', self::EFECTIVO)
                ->orWhereNull('p.provider')),
            default => null,
        };

        match ($request->query('status_group')) {
            'aprobados'  => $q->whereIn('p.status', self::PAGO_OK),
            'pendientes' => $q->where('p.status', 'like', 'pending%'),
            'fallidos'   => $q->whereIn('p.status', self::PAGO_FALLIDO),
            default      => null,
        };
    }

    /**
     * Indicadores de la pantalla de pagos, sobre TODO lo filtrado.
     *
     * `COALESCE(total, amount)`: `total` es el campo nuevo y hay cobros viejos
     * que solo tienen `amount`. Sumar únicamente `total` dejaba fuera la caja
     * anterior a la migración sin avisar de nada.
     */
    private function resumenDePagos($q): array
    {
        $ok       = "'" . implode("','", self::PAGO_OK) . "'";
        $efectivo = self::EFECTIVO;

        $r = ListadoPaginado::soloAgregados($q, "
            COALESCE(SUM(CASE WHEN p.status IN ({$ok}) THEN COALESCE(p.total, p.amount) ELSE 0 END), 0) as recaudado,
            SUM(CASE WHEN p.status IN ({$ok}) THEN 1 ELSE 0 END) as aprobados_n,
            COALESCE(SUM(CASE WHEN p.status LIKE 'pending%' THEN COALESCE(p.total, p.amount) ELSE 0 END), 0) as pendiente,
            SUM(CASE WHEN p.status LIKE 'pending%' THEN 1 ELSE 0 END) as pendiente_n,
            COALESCE(SUM(CASE WHEN p.status IN ({$ok}) AND p.provider = '{$efectivo}' THEN COALESCE(p.total, p.amount) ELSE 0 END), 0) as efectivo,
            SUM(CASE WHEN p.status IN ({$ok}) AND p.provider = '{$efectivo}' THEN 1 ELSE 0 END) as efectivo_n,
            COALESCE(SUM(CASE WHEN p.status IN ({$ok}) AND (p.provider <> '{$efectivo}' OR p.provider IS NULL) THEN COALESCE(p.total, p.amount) ELSE 0 END), 0) as pasarela,
            SUM(CASE WHEN p.status IN ({$ok}) AND (p.provider <> '{$efectivo}' OR p.provider IS NULL) THEN 1 ELSE 0 END) as pasarela_n,
            COALESCE(SUM(CASE WHEN p.status IN ({$ok}) THEN p.domiciliary_fee ELSE 0 END), 0) as comisiones
        ");

        return [
            'recaudado'   => round((float) ($r->recaudado ?? 0), 2),
            'aprobadosN'  => (int) ($r->aprobados_n ?? 0),
            'pendiente'   => round((float) ($r->pendiente ?? 0), 2),
            'pendienteN'  => (int) ($r->pendiente_n ?? 0),
            'efectivo'    => round((float) ($r->efectivo ?? 0), 2),
            'efectivoN'   => (int) ($r->efectivo_n ?? 0),
            'pasarela'    => round((float) ($r->pasarela ?? 0), 2),
            'pasarelaN'   => (int) ($r->pasarela_n ?? 0),
            'comisiones'  => round((float) ($r->comisiones ?? 0), 2),
        ];
    }

    /* ==================================================================
       RESEÑAS
       ================================================================== */
}
