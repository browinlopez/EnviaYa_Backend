<?php

namespace App\Http\Controllers\Negocio;

use App\Http\Controllers\Controller;
use App\Models\Order\OrdersSales;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Lo que el panel del tendero necesita saber al entrar.
 *
 * Devuelve la MISMA FORMA que `/conjunto/me` y `/admin/me/permissions`
 * —permisos por clave con `view` y `manage`— a propósito: el panel de aliados
 * ya tiene contexto de sesión, menú por permisos y guardia de rutas probados
 * contra esa forma, y hospedar al tendero no debería obligar a escribir una
 * segunda versión de los tres. Lo que cambia es de dónde salen los permisos:
 * acá no hay áreas ni roles, hay un solo perfil.
 *
 * La diferencia con el conjunto es `businesses`: un tendero puede administrar
 * más de uno —hoy hay dos así en la base— y el panel necesita la lista para
 * poder cambiar de local. El móvil se queda con el primero y nunca deja llegar
 * al segundo.
 */
class MiNegocioController extends Controller
{
    /**
     * Un solo perfil, sin matriz configurable.
     *
     * `manage` en todo menos en reseñas: lo que escribe un comprador sobre la
     * tienda no lo puede editar ni borrar la tienda. Una calificación que el
     * calificado puede quitar no es una calificación.
     */
    private const PERMISOS = [
        'pedidos'       => ['view' => true, 'manage' => true],
        'productos'     => ['view' => true, 'manage' => true],
        'domiciliarios' => ['view' => true, 'manage' => true],
        'resenas'       => ['view' => true, 'manage' => false],
        'negocio'       => ['view' => true, 'manage' => true],
    ];

    public function mio(Request $request)
    {
        $negocio  = $request->attributes->get('negocio');
        $negocios = $request->attributes->get('negocios');

        return response()->json([
            'business'    => $this->ficha($negocio),
            'businesses'  => $negocios->map(fn ($b) => [
                'busines_id' => (int) $b->busines_id,
                'name'       => $b->name,
                'logo'       => $b->logo,
                'state'      => (int) $b->state,
            ])->values(),
            'role'        => 'tendero',
            'permissions' => self::PERMISOS,
            'stats'       => $this->resumen((int) $negocio->busines_id),
        ]);
    }

    /**
     * El tendero corrige la ficha de su propia tienda.
     *
     * NO se reutiliza `BusinessController@update` a pesar de que hace justo
     * esto, por dos campos que acepta y que acá no pueden estar:
     *
     *  · `owner_ids` — hace `sync()` sobre los dueños. Quien la llame puede
     *    quitarse a los socios de encima, o meterse en un negocio ajeno. Es de
     *    la administración, no de la tienda.
     *  · `state` — dar de baja un negocio es una decisión de la plataforma, la
     *    misma que toma el panel interno. Si el tendero pudiera apagarse,
     *    desaparecería del catálogo sin que nadie supiera por qué.
     *
     * `NIT` y `razonSocial_DCD` sí se dejan: son los datos con los que sale su
     * nombre en el comprobante de venta, y quien los conoce es él.
     */
    public function actualizar(Request $request)
    {
        $negocio = $request->attributes->get('negocio');

        $datos = $request->validate([
            'name'            => 'required|string|max:255',
            'phone'           => 'nullable|string|max:40',
            'address'         => 'nullable|string|max:255',
            'description'     => 'nullable|string|max:1000',
            'NIT'             => 'nullable|string|max:40',
            'razonSocial_DCD' => 'nullable|string|max:255',
            'municipality_id' => 'nullable|integer|exists:municipalities,id',
            /*
             * Cuánto efectivo deja que su domiciliario lleve encima. Vacío =
             * sin tope, que es como funciona si nadie lo toca.
             *
             * `nullable` de verdad: un cero significaría «no le despaches ni un
             * pedido contra entrega», que es una decisión legítima pero muy
             * distinta de no haber puesto tope. Se distinguen.
             */
            'max_courier_cash' => 'nullable|numeric|min:0|max:99999999',
        ]);

        // Cadena vacía desde un formulario es «sin tope», no cero.
        if (array_key_exists('max_courier_cash', $datos) && $datos['max_courier_cash'] === '') {
            $datos['max_courier_cash'] = null;
        }

        $negocio->fill($datos)->save();

        return response()->json([
            'message'  => 'Datos de tu negocio actualizados.',
            'business' => $this->ficha($negocio->fresh()),
        ]);
    }

    private function ficha($b): array
    {
        return [
            'busines_id'      => (int) $b->busines_id,
            'name'            => $b->name,
            'phone'           => $b->phone,
            'address'         => $b->address,
            'description'     => $b->description,
            'NIT'             => $b->NIT,
            'razonSocial_DCD' => $b->razonSocial_DCD,
            'logo'            => $b->logo,
            'type'            => (int) $b->type,
            'state'           => (int) $b->state,
            'qualification'   => $b->qualification !== null ? (float) $b->qualification : null,
            'max_courier_cash' => $b->max_courier_cash !== null ? (float) $b->max_courier_cash : null,
            'municipality_id' => $b->municipality_id !== null ? (int) $b->municipality_id : null,
            'latitude'        => $b->latitude !== null ? (float) $b->latitude : null,
            'longitude'       => $b->longitude !== null ? (float) $b->longitude : null,
        ];
    }

    /**
     * Las cuatro cifras del encabezado.
     *
     * `confirmados()` en todas: un pedido cuyo pago en línea sigue sin
     * confirmarse no debe existir para la tienda, ni para contarlo ni para
     * ponerse a prepararlo.
     *
     * "Hoy" se corta por `sale_date` y no por `created_at` porque es la fecha
     * con la que el resto del proyecto —liquidaciones, reportes, ingresos—
     * decide a qué día pertenece una venta. Dos definiciones de "hoy" darían
     * dos totales distintos para lo mismo, y el tendero los compararía.
     */
    private function resumen(int $businessId): array
    {
        $base = fn () => OrdersSales::where('busines_id', $businessId)->confirmados();

        $hoy = $base()->whereDate('sale_date', now()->toDateString());

        return [
            // Los que esperan que alguien pulse "Aceptar". Es la cifra que
            // manda en este panel: mientras no baje a cero, hay gente
            // esperando sin saber si su pedido fue visto.
            'nuevos'    => (int) $base()->where('state', 1)->count(),
            'en_curso'  => (int) $base()->whereIn('state', [2, 3])->count(),
            'hoy'       => (int) (clone $hoy)->count(),
            // Solo el subtotal: el domicilio no es del negocio, es del
            // domiciliario. Sumar el total le enseñaría una venta más alta que
            // la que se le va a liquidar.
            'venta_hoy' => (float) (clone $hoy)->whereIn('state', [2, 3, 4])->sum(DB::raw('subtotal - discount')),
        ];
    }
}
