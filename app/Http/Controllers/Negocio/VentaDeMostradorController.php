<?php

declare(strict_types=1);

namespace App\Http\Controllers\Negocio;

use App\Http\Controllers\Controller;
use App\Models\Negocio\LineaDeVentaDeMostrador;
use App\Models\Negocio\VentaDeMostrador;
use App\Models\Product\ProductBusiness;
use App\Services\CobroDeMostradorConQr;
use App\Support\UnidadDeVenta;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * LA VENTA EN EL MOSTRADOR: lo que se vende en persona, sin app.
 *
 * El vecino entra, pide «$1.500 de queso y dos Pony Malta», paga en
 * efectivo y se va. Anotarlo acá hace dos cosas: descuenta el inventario y
 * entra en «Cuánto te queda», que de otro modo solo vería los pedidos de la
 * app. Sin comisión: no pasó por la plataforma.
 *
 * El precio lo pone el servidor, igual que en un pedido, salvo cuando el
 * tendero lo cambió a mano para esa venta (`price` en la línea).
 */
class VentaDeMostradorController extends Controller
{
    private function negocio(Request $request): int
    {
        return (int) $request->attributes->get('busines_id');
    }

    /** POST negocio/ventas-mostrador */
    public function registrar(Request $request): JsonResponse
    {
        $datos = $request->validate([
            'lineas' => 'required|array|min:1|max:100',
            'lineas.*.products_id' => 'required|integer',
            'lineas.*.amount' => 'nullable|integer|min:1|max:1000',
            'lineas.*.pedido_por' => 'nullable|in:valor,cantidad',
            'lineas.*.requested_value' => 'nullable|numeric|min:100|max:1000000',
            'lineas.*.requested_qty' => 'nullable|numeric|min:0.001|max:5000',
            /*
             * EL PRECIO QUE SE CAMBIÓ EN EL MOSTRADOR. En persona el tendero
             * rebaja o redondea --«a usted se lo dejo en $3.000»--: es su
             * venta, en su tienda. Sin él, manda el precio del producto.
             */
            'lineas.*.price' => 'nullable|numeric|min:1|max:100000000',
            // `qr`: Bre-B por Bold. Ver `CobroDeMostradorConQr`.
            'forma_de_pago' => 'nullable|in:efectivo,transferencia,qr',
            'cash_tendered' => 'nullable|numeric|min:0|max:10000000',
        ]);

        $negocio = $this->negocio($request);
        $ids = collect($datos['lineas'])->pluck('products_id')->unique()->all();

        $surtido = ProductBusiness::where('busines_id', $negocio)
            ->whereIn('products_id', $ids)
            ->with('product:products_id,name')
            ->get()
            ->keyBy('products_id');

        $faltan = collect($ids)->reject(fn ($id) => $surtido->has($id))->values();
        if ($faltan->isNotEmpty()) {
            return response()->json([
                'message' => 'Hay productos que no están en tu negocio.',
                'product_ids' => $faltan,
            ], 422);
        }

        $lineas = [];
        foreach ($datos['lineas'] as $l) {
            $pb = $surtido[$l['products_id']];
            $unidad = $pb->sale_unit ?: UnidadDeVenta::UNIDAD;
            $precio = isset($l['price']) ? (float) $l['price'] : (float) $pb->price;
            $nombre = (string) ($pb->product->name ?? '');

            if (! UnidadDeVenta::esPeso($unidad)) {
                $cantidad = (float) ($l['amount'] ?? 1);
                $lineas[] = $this->linea($pb, $nombre, $unidad, null, $cantidad, $precio, $precio * $cantidad);

                continue;
            }

            $porValor = ($l['pedido_por'] ?? null) === 'valor';

            if ($porValor && ! $pb->por_valor) {
                return response()->json([
                    'message' => "«{$nombre}» no se vende por valor. Márcalo en su ficha o véndelo por peso.",
                    'product_ids' => [(int) $pb->products_id],
                ], 422);
            }

            if ($porValor) {
                $plata = (float) ($l['requested_value'] ?? 0);
                if ($plata <= 0 || $precio <= 0) {
                    return response()->json(['message' => "Falta cuánto de «{$nombre}»."], 422);
                }
                $lineas[] = $this->linea($pb, $nombre, $unidad, 'valor', round($plata / $precio, 3), $precio, $plata);

                continue;
            }

            $cantidad = (float) ($l['requested_qty'] ?? 0);
            if ($cantidad <= 0 || $cantidad > UnidadDeVenta::maximo($unidad)) {
                return response()->json(['message' => "Revisa la cantidad de «{$nombre}»."], 422);
            }
            $lineas[] = $this->linea($pb, $nombre, $unidad, 'cantidad', $cantidad, $precio, $precio * $cantidad);
        }

        $total = round(array_sum(array_column($lineas, 'line_total')), 2);
        $forma = $datos['forma_de_pago'] ?? 'efectivo';
        $recibido = isset($datos['cash_tendered']) ? (float) $datos['cash_tendered'] : null;

        if ($forma === 'efectivo' && $recibido !== null && $recibido > 0 && $recibido < $total) {
            return response()->json([
                'message' => 'Con eso no alcanza: la venta es de $'.number_format($total, 0, ',', '.').'.',
            ], 422);
        }

        $venta = DB::transaction(function () use ($negocio, $request, $lineas, $total, $forma, $recibido) {
            $venta = VentaDeMostrador::create([
                'busines_id' => $negocio,
                'user_id' => $request->user()?->user_id,
                'total' => $total,
                'forma_de_pago' => $forma,
                // El QR nace esperando: solo cuenta cuando Bold confirma.
                'estado_pago' => $forma === 'qr' ? 'esperando' : 'pagada',
                'cash_tendered' => $forma === 'efectivo' && $recibido ? $recibido : null,
                'change_given' => $forma === 'efectivo' && $recibido ? round($recibido - $total, 2) : null,
                'vendida_at' => now(),
            ]);

            foreach ($lineas as $l) {
                LineaDeVentaDeMostrador::create(['venta_id' => $venta->venta_id] + $l);

                /*
                 * El inventario se mueve igual que con un pedido, y como en
                 * los pedidos puede quedar en negativo: la tienda vende lo que
                 * tiene en la mano aunque la app diga cero.
                 */
                $q = DB::table('products_business')
                    ->where('busines_id', $negocio)
                    ->where('products_id', $l['products_id']);

                UnidadDeVenta::esPeso($l['sale_unit'])
                    ? $q->decrement('stock_weight', $l['cantidad'])
                    : $q->decrement('amount', (int) $l['cantidad']);
            }

            return $venta;
        });

        /*
         * CON QR, SE PIDE EL CÓDIGO A BOLD. Si la pasarela falla, la venta se
         * deshace --inventario incluido-- y se dice: el vecino sigue ahí y el
         * tendero tiene que poder cobrarle de otra forma.
         */
        if ($forma === 'qr') {
            $cobro = app(CobroDeMostradorConQr::class);
            try {
                $venta = $cobro->cobrar($venta);
            } catch (\Throwable $e) {
                Log::warning('No se pudo generar el QR del mostrador', [
                    'venta' => $venta->venta_id,
                    'error' => $e->getMessage(),
                ]);
                $cobro->rechazar($venta, 'cancelada');

                return response()->json([
                    'message' => 'No pudimos generar el código QR. Cobra en efectivo o inténtalo otra vez.',
                ], 422);
            }
        }

        return response()->json([
            'message' => $forma === 'qr' ? 'Muéstrale el código al cliente' : 'Venta registrada',
            'data' => $this->forma($venta->load('lineas')),
        ], 201);
    }

    /**
     * GET negocio/ventas-mostrador/{id}/pago — cómo va el pago con QR. Si
     * sigue esperando se le pregunta a Bold: en local el aviso nunca llega,
     * y en producción puede tardar.
     */
    public function pago(Request $request, int $id, CobroDeMostradorConQr $cobro): JsonResponse
    {
        $venta = VentaDeMostrador::where('busines_id', $this->negocio($request))->findOrFail($id);
        $venta = $cobro->consultar($venta);

        return response()->json(['data' => $this->forma($venta->load('lineas'))]);
    }

    /** POST negocio/ventas-mostrador/{id}/cancelar — el vecino no pagó con QR. */
    public function cancelar(Request $request, int $id, CobroDeMostradorConQr $cobro): JsonResponse
    {
        $venta = VentaDeMostrador::where('busines_id', $this->negocio($request))->findOrFail($id);

        // Antes de cancelar se pregunta: puede que justo haya pagado.
        $venta = $cobro->consultar($venta);
        if ($venta->estado_pago === 'pagada') {
            return response()->json([
                'message' => 'Ya pagó: la venta quedó anotada.',
                'data' => $this->forma($venta->load('lineas')),
            ], 409);
        }

        $cobro->rechazar($venta, 'cancelada');

        return response()->json(['message' => 'Venta cancelada']);
    }

    /** GET negocio/ventas-mostrador?fecha=YYYY-MM-DD — las ventas de ese día. */
    public function delDia(Request $request): JsonResponse
    {
        $datos = $request->validate(['fecha' => 'nullable|date']);
        $dia = isset($datos['fecha']) ? Carbon::parse($datos['fecha']) : now();

        $ventas = VentaDeMostrador::where('busines_id', $this->negocio($request))
            ->whereBetween('vendida_at', [$dia->copy()->startOfDay(), $dia->copy()->endOfDay()])
            ->where('estado_pago', 'pagada')
            ->with('lineas')
            ->orderByDesc('vendida_at')
            ->get();

        return response()->json([
            'data' => [
                'fecha' => $dia->toDateString(),
                'total' => round((float) $ventas->sum('total'), 2),
                'ventas' => $ventas->map(fn ($v) => $this->forma($v))->values(),
            ],
        ]);
    }

    /** @return array<string, mixed> */
    private function linea(ProductBusiness $pb, string $nombre, string $unidad, ?string $por, float $cantidad, float $precio, float $total): array
    {
        return [
            'products_id' => (int) $pb->products_id,
            'nombre' => $nombre,
            'sale_unit' => $unidad,
            'pedido_por' => $por,
            'cantidad' => $cantidad,
            'unit_price' => $precio,
            'unit_cost' => $pb->costo,
            'line_total' => round($total, 2),
        ];
    }

    /** @return array<string, mixed> */
    private function forma(VentaDeMostrador $v): array
    {
        return [
            'venta_id' => (int) $v->venta_id,
            'total' => (float) $v->total,
            'forma_de_pago' => $v->forma_de_pago,
            'estado_pago' => $v->estado_pago ?? 'pagada',
            // El código lo pinta la app tal cual: PNG en base64.
            'qr_payload' => $v->estado_pago === 'esperando' ? $v->qr_payload : null,
            'qr_expira_at' => $v->qr_expira_at?->toIso8601String(),
            'cash_tendered' => $v->cash_tendered !== null ? (float) $v->cash_tendered : null,
            'change_given' => $v->change_given !== null ? (float) $v->change_given : null,
            'vendida_at' => $v->vendida_at?->toIso8601String(),
            'lineas' => $v->lineas->map(fn ($l) => [
                'products_id' => (int) $l->products_id,
                'nombre' => $l->nombre,
                'sale_unit' => $l->sale_unit,
                'pedido_por' => $l->pedido_por,
                'cantidad' => (float) $l->cantidad,
                'line_total' => (float) $l->line_total,
            ])->values(),
        ];
    }
}
