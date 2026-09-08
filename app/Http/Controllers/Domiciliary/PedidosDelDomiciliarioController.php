<?php

namespace App\Http\Controllers\Domiciliary;

use App\Http\Controllers\Controller;
use App\Models\Domiciliary;
use App\Models\Order\OrdersSales;
use App\Services\PedidoParaLaApp;
use Illuminate\Http\Request;

/**
 * Los pedidos de un domiciliario: los suyos y los que puede tomar.
 *
 * POR QUÉ EXISTE. La app del domiciliario pedía su lista a
 * `POST /v1/orders/business`, o sea que se descargaba los pedidos ENTEROS de
 * la tienda —con nombre, teléfono y dirección de cada comprador— y filtraba
 * en el teléfono los que eran suyos. Cuando ese endpoint se cerró a quien no
 * es dueño del negocio, el domiciliario se quedó viendo «0 de 3» y «no hay
 * pedidos por tomar» con cuatro entregas encima.
 *
 * Acá el ámbito no se pide: sale de la sesión. Un domiciliario no puede
 * consultar la lista de otro ni pasando su número.
 */
class PedidosDelDomiciliarioController extends Controller
{
    /** `orderssales.state`: 2 listo para recoger · 3 en camino · 4 entregado. */
    private const POR_TOMAR = 2;
    private const EN_CAMINO = 3;
    private const ENTREGADO = 4;

    public function __construct(private readonly PedidoParaLaApp $presentador)
    {
    }

    public function mios(Request $request)
    {
        $domiciliary = Domiciliary::with('businesses')
            ->where('user_id', $request->user()->user_id)
            ->first();

        if (!$domiciliary) {
            return response()->json([
                'message' => 'Esta cuenta no es de un domiciliario.',
            ], 403);
        }

        $negocios = $domiciliary->businesses->pluck('busines_id')->all();

        /*
         * MÍOS: los que ya me asignaron, en camino o entregados.
         *
         * Van con los datos del comprador porque tengo que llegar a su puerta
         * —y llamarlo si no encuentro el timbre—.
         */
        $mios = OrdersSales::where('domiciliary_id', $domiciliary->domiciliary_id)
            ->whereIn('state', [self::EN_CAMINO, self::ENTREGADO])
            ->confirmados()
            ->with($this->relaciones())
            ->get();

        /*
         * POR TOMAR: listos en alguna de MIS tiendas y sin nadie asignado.
         *
         * Estos van SIN los datos de la persona: todavía no tengo nada que ver
         * con ese pedido. Veo de qué tienda es, cuánto suma y cuánto gano, que
         * es lo que hace falta para decidir si lo tomo. Al tomarlo pasa a la
         * lista de arriba y ahí sí llega la dirección.
         */
        $porTomar = $negocios
            ? OrdersSales::whereIn('busines_id', $negocios)
                ->where('state', self::POR_TOMAR)
                ->whereNull('domiciliary_id')
                ->confirmados()
                ->with($this->relaciones())
                ->get()
            : collect();

        $pedidos = $mios
            ->map(fn (OrdersSales $o) => $this->presentador->formatear($o, true))
            ->concat(
                $porTomar->map(fn (OrdersSales $o) => $this->presentador->formatear($o, false))
            )
            ->values();

        return response()->json([
            'message' => 'Pedidos del domiciliario',
            // La app arma su pantalla con una sola lista y la reparte por
            // estado, igual que hacía con la de la tienda. Se conserva el
            // nombre `orders` para no tener que tocar el hook.
            'orders'  => $pedidos,
            'resumen' => [
                'en_camino'  => $mios->where('state', self::EN_CAMINO)->count(),
                'entregados' => $mios->where('state', self::ENTREGADO)->count(),
                'por_tomar'  => $porTomar->count(),
            ],
        ]);
    }

    /** Lo que el presentador necesita cargado para no disparar N+1. */
    private function relaciones(): array
    {
        return [
            'business',
            'details.product',
            'buyer.user',
            'promotions',
            'payments',
            'domiciliary.user',
            'address.municipality.department.country',
            'address.alias',
        ];
    }
}
