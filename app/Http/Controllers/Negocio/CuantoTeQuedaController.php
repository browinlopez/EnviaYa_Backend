<?php

declare(strict_types=1);

namespace App\Http\Controllers\Negocio;

use App\Http\Controllers\Controller;
use App\Http\Requests\Negocio\ActualizarGastoRequest;
use App\Http\Requests\Negocio\CrearGastoRequest;
use App\Http\Requests\Negocio\CuentaDelNegocioRequest;
use App\Http\Requests\Negocio\GuardarCostosRequest;
use App\Models\Negocio\GastoDelMes;
use App\Models\Negocio\GastoDelNegocio;
use App\Models\Order\OrdersSales;
use App\Models\Product\ProductBusiness;
use App\Services\CuentaDelNegocio;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * «CUÁNTO TE QUEDA»: la cuenta del negocio, los costos de sus productos y
 * sus gastos. Ver `App\Services\CuentaDelNegocio`.
 *
 * Todo va dentro del grupo `negocio`: el negocio sale de la sesión
 * (`busines_id` del middleware), nunca de lo que mande la app.
 */
class CuantoTeQuedaController extends Controller
{
    private function negocio(Request $request): int
    {
        return (int) $request->attributes->get('busines_id');
    }

    /** GET negocio/cuanto-te-queda?periodo=mes|semana&fecha=YYYY-MM-DD */
    public function cuenta(CuentaDelNegocioRequest $request, CuentaDelNegocio $cuenta): JsonResponse
    {
        $datos = $request->validated();

        return response()->json($cuenta->para(
            $this->negocio($request),
            $datos['periodo'] ?? 'mes',
            isset($datos['fecha']) ? Carbon::parse($datos['fecha']) : now(),
        ));
    }

    /**
     * GET negocio/costos — los productos de la tienda con su precio y su
     * costo, los que más se venden primero: son los que más mueven la cuenta.
     */
    public function costos(Request $request): JsonResponse
    {
        $negocio = $this->negocio($request);

        $vendidos = DB::table('orderssales_detail as d')
            ->join('orderssales as o', 'o.orderSales_id', '=', 'd.orderSales_id')
            ->where('o.busines_id', $negocio)
            ->where('o.state', OrdersSales::ENTREGADO)
            ->where('o.delivery_date', '>=', now()->subDays(60))
            ->groupBy('d.product_id')
            ->pluck(DB::raw('COUNT(*) as veces'), 'd.product_id');

        $productos = DB::table('products_business as pb')
            ->join('products as p', 'p.products_id', '=', 'pb.products_id')
            ->where('pb.busines_id', $negocio)
            ->get(['pb.products_id', 'p.name', 'p.image', 'pb.price', 'pb.costo', 'pb.sale_unit'])
            ->map(fn ($r) => [
                'products_id' => (int) $r->products_id,
                'nombre' => (string) $r->name,
                'imagen' => $r->image,
                'precio' => (float) $r->price,
                'costo' => $r->costo !== null ? (float) $r->costo : null,
                'unidad' => $r->sale_unit,
                'vendidos' => (int) ($vendidos[$r->products_id] ?? 0),
            ])
            ->sortBy([['vendidos', 'desc'], ['nombre', 'asc']])
            ->values();

        return response()->json([
            'productos' => $productos,
            'sin_costo' => $productos->whereNull('costo')->count(),
        ]);
    }

    /** PUT negocio/costos — { costos: [{ products_id, costo }] }. `costo` null lo borra. */
    public function guardarCostos(GuardarCostosRequest $request): JsonResponse
    {
        $datos = $request->validated();

        $negocio = $this->negocio($request);
        $cambiados = 0;

        foreach ($datos['costos'] as $c) {
            $cambiados += ProductBusiness::where('busines_id', $negocio)
                ->where('products_id', $c['products_id'])
                ->update(['costo' => $c['costo'] ?? null]);
        }

        return response()->json(['message' => 'Costos guardados.', 'cambiados' => $cambiados]);
    }

    /** GET negocio/gastos — los vigentes, con lo que valen este mes. */
    public function gastos(Request $request): JsonResponse
    {
        $periodo = now()->format('Y-m');
        $gastos = GastoDelNegocio::where('busines_id', $this->negocio($request))
            ->where(fn ($q) => $q->whereNull('hasta')->orWhere('hasta', '>=', now()->startOfMonth()))
            ->with('meses')
            ->orderBy('frecuencia')
            ->orderByDesc('monto')
            ->get()
            ->map(fn (GastoDelNegocio $g) => $this->comoSeVe($g, $periodo));

        return response()->json([
            'gastos' => $gastos,
            'tipos' => GastoDelNegocio::TIPOS,
        ]);
    }

    /** POST negocio/gastos */
    public function crearGasto(CrearGastoRequest $request): JsonResponse
    {
        $datos = $request->validated();

        $gasto = GastoDelNegocio::create([
            'busines_id' => $this->negocio($request),
            'tipo' => $datos['tipo'],
            'nombre' => trim((string) ($datos['nombre'] ?? '')) ?: GastoDelNegocio::TIPOS[$datos['tipo']],
            'monto' => $datos['monto'],
            'frecuencia' => $datos['frecuencia'],
            // Un fijo cuenta desde el principio de su mes: la luz de noviembre
            // es de noviembre aunque se anote el 20.
            'desde' => $datos['frecuencia'] === GastoDelNegocio::MENSUAL
                ? now()->startOfMonth()->toDateString()
                : Carbon::parse($datos['fecha'] ?? now())->toDateString(),
        ]);

        return response()->json($this->comoSeVe($gasto->load('meses'), now()->format('Y-m')), 201);
    }

    /**
     * PUT negocio/gastos/{id} — { monto?, nombre?, solo_este_mes? }.
     * Con `solo_este_mes`, el valor cambia nada más este mes (el recibo que
     * vino distinto); sin él, cambia de aquí en adelante.
     */
    public function actualizarGasto(ActualizarGastoRequest $request, int $id): JsonResponse
    {
        $datos = $request->validated();

        $gasto = $this->delNegocio($request, $id);
        $periodo = now()->format('Y-m');

        if (! empty($datos['solo_este_mes']) && isset($datos['monto'])
            && $gasto->frecuencia === GastoDelNegocio::MENSUAL) {
            GastoDelMes::updateOrCreate(
                ['gasto_id' => $gasto->gasto_id, 'periodo' => $periodo],
                ['monto' => $datos['monto']],
            );
        } else {
            $gasto->fill(array_filter([
                'monto' => $datos['monto'] ?? null,
                'nombre' => isset($datos['nombre']) ? trim($datos['nombre']) : null,
            ], fn ($v) => $v !== null && $v !== ''))->save();
            // El valor nuevo manda desde este mes: si había uno solo para
            // este mes, deja de valer.
            GastoDelMes::where('gasto_id', $gasto->gasto_id)->where('periodo', $periodo)->delete();
        }

        return response()->json($this->comoSeVe($gasto->load('meses'), $periodo));
    }

    /**
     * DELETE negocio/gastos/{id}. Un fijo que viene de meses atrás no se
     * borra: deja de contar desde este mes, y los anteriores siguen
     * diciendo lo que costaron.
     */
    public function quitarGasto(Request $request, int $id): JsonResponse
    {
        $gasto = $this->delNegocio($request, $id);
        $esteMes = now()->startOfMonth();

        if ($gasto->frecuencia === GastoDelNegocio::MENSUAL && $gasto->desde->lt($esteMes)) {
            $gasto->hasta = $esteMes->copy()->subDay();
            $gasto->save();
        } else {
            GastoDelMes::where('gasto_id', $gasto->gasto_id)->delete();
            $gasto->delete();
        }

        return response()->json(['message' => 'Gasto quitado.']);
    }

    private function delNegocio(Request $request, int $id): GastoDelNegocio
    {
        return GastoDelNegocio::where('busines_id', $this->negocio($request))
            ->where('gasto_id', $id)
            ->firstOrFail();
    }

    /** @return array<string, mixed> */
    private function comoSeVe(GastoDelNegocio $g, string $periodo): array
    {
        $delMes = $g->meses->firstWhere('periodo', $periodo);

        return [
            'gasto_id' => (int) $g->gasto_id,
            'tipo' => $g->tipo,
            'nombre' => $g->nombre,
            'monto' => (float) $g->monto,
            'monto_este_mes' => (float) ($delMes->monto ?? $g->monto),
            'cambiado_este_mes' => (bool) $delMes,
            'frecuencia' => $g->frecuencia,
            'desde' => $g->desde?->toDateString(),
        ];
    }
}
