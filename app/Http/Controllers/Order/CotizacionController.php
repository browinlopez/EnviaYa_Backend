<?php

namespace App\Http\Controllers\Order;

use App\Http\Controllers\Controller;
use App\Models\Business;
use App\Services\EconomiaDelPedido;
use App\Services\TarifaPorDistancia;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * CUÁNTO VA A COSTAR ESTE CARRITO, ANTES DE PEDIRLO
 *
 * POR QUÉ EXISTE. La app calculaba el total ella sola: sumaba precios y le
 * añadía el domicilio. Mientras las cifras eran dos —precio y tarifa— se pudo
 * sostener, y aun así costó un fallo caro: la tarifa vivía escrita en la app y
 * en el servidor, se prometían $2.000 y se cobraban $14.000.
 *
 * Ahora las cifras son seis, y una de ellas —el descuento por promociones—
 * depende de reglas que solo el servidor conoce: qué promociones están
 * vigentes, cuál rebaja más, cuántas unidades hacen falta. Duplicar eso en la
 * app es garantizar que un día digan cosas distintas, y en un carrito «decir
 * cosas distintas» significa cobrar de más.
 *
 * Así que el carrito PREGUNTA en vez de calcular. Devuelve exactamente el
 * mismo desglose que se va a congelar en el pedido, porque lo produce el mismo
 * `EconomiaDelPedido` que usa `store`.
 *
 * NO CREA NADA ni reserva stock: es una consulta. Se puede llamar en cada
 * cambio del carrito sin dejar rastro.
 */
class CotizacionController extends Controller
{
    public function __construct(
        private EconomiaDelPedido $economia,
        private TarifaPorDistancia $distancias,
    ) {
    }

    public function cotizar(Request $request)
    {
        // Las mismas dos grafías que acepta `store`: la app manda
        // `business_id` y `quantity`; el contrato viejo, `busines_id` y
        // `amount`.
        if (!$request->filled('busines_id') && $request->filled('business_id')) {
            $request->merge(['busines_id' => $request->input('business_id')]);
        }

        $request->merge([
            'products' => collect($request->input('products', []))
                ->map(fn ($p) => [
                    'product_id' => $p['product_id'] ?? null,
                    'amount'     => $p['amount'] ?? $p['quantity'] ?? null,
                ])->all(),
        ]);

        $request->validate([
            'busines_id'            => 'required|integer|exists:business,busines_id',
            'products'              => 'required|array|min:1',
            'products.*.product_id' => 'required|integer',
            'products.*.amount'     => 'required|integer|min:1|max:1000',
            'address_id'            => 'sometimes|nullable|integer',
            'pickup'                => 'sometimes|boolean',
            'coupon_code'           => 'sometimes|nullable|string|max:40',
        ]);

        $business = Business::find($request->busines_id);
        $esRecogida = (bool) $request->boolean('pickup');

        [$precios, $faltantes] = $this->economia->preciosDelServidor(
            $request->products,
            (int) $business->busines_id,
        );

        /*
         * Un producto que ya no se vende NO tumba la cotización.
         *
         * `store` responde 422 y no crea el pedido, que es lo correcto al
         * pedir. Acá lo correcto es lo contrario: el carrito tiene que poder
         * pintar el total de lo que sí queda y decir qué se cayó, en vez de
         * quedarse en blanco mientras el cliente mira.
         */
        $vigentes = collect($request->products)
            ->filter(fn ($p) => $precios->has((int) $p['product_id']))
            ->values()
            ->all();

        if ($vigentes === []) {
            return response()->json([
                'message'   => 'Ninguno de esos productos sigue disponible en la tienda.',
                'no_estan'  => $faltantes->values(),
            ], 422);
        }

        // La dirección tiene que ser de quien pregunta: sin esta comprobación
        // se podría averiguar la tarifa —y por tanto la distancia— hasta la
        // puerta de cualquiera pasando números.
        $direccion = null;

        if (!$esRecogida && $request->filled('address_id')) {
            $direccion = DB::table('user_address')
                ->where('address_id', $request->address_id)
                ->where('user_id', $request->user()->user_id)
                ->first();
        }

        $cifras = $this->economia->calcular(
            $vigentes,
            $precios,
            $request->input('coupon_code'),
            (int) $request->user()->user_id,
            (int) $business->busines_id,
            $esRecogida,
            $business->latitude !== null ? (float) $business->latitude : null,
            $business->longitude !== null ? (float) $business->longitude : null,
            $direccion?->latitude !== null ? (float) $direccion?->latitude : null,
            $direccion?->longitude !== null ? (float) $direccion?->longitude : null,
        );

        return response()->json([
            'subtotal'          => round($cifras['subtotal'], 2),
            'descuento'         => $cifras['descuento'],
            'descuento_cupon'   => $cifras['descuentoCupon'],
            'descuento_promo'   => $cifras['descuentoPromo'],
            'promociones'       => $cifras['promociones'],
            'promociones_cerca' => $cifras['promocionesCerca'],
            'domicilio'         => round($cifras['domicilio'], 2),
            'total'             => round($cifras['total'], 2),
            'distancia_km'      => $cifras['km'],
            /*
             * Si reparte hasta acá. El carrito ya lo comprobaba por su cuenta
             * con `in_range` del listado, que es una foto del momento en que
             * se cargó; esto es el dato de ahora y de esta dirección.
             */
            'reparte'           => $esRecogida || $cifras['km'] === null
                ? true
                : $this->distancias->reparteHasta($cifras['km']),
            // Lo que se cayó del carrito, para poder decirlo.
            'no_estan'          => $faltantes->values(),
        ]);
    }
}
