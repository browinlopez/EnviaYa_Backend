<?php

declare(strict_types=1);

namespace App\Http\Controllers\Negocio;

use App\Http\Controllers\Controller;
use App\Http\Requests\Negocio\ActualizarMiNegocioRequest;
use App\Http\Requests\Negocio\RecibirPedidosRequest;
use App\Http\Requests\Negocio\SubirLogoRequest;
use App\Http\Resources\Negocio\MiNegocioResource;
use App\Models\Order\OrdersSales;
use App\Services\BusinessMediaService;
use App\Support\FiltroDeFechas;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use RuntimeException;

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
        'pedidos' => ['view' => true, 'manage' => true],
        'productos' => ['view' => true, 'manage' => true],
        'domiciliarios' => ['view' => true, 'manage' => true],
        'torre' => ['view' => true, 'manage' => false],
        'chats' => ['view' => true, 'manage' => true],
        'promociones' => ['view' => true, 'manage' => true],
        'clientes' => ['view' => true, 'manage' => true],
        'ingresos' => ['view' => true, 'manage' => false],
        'resenas' => ['view' => true, 'manage' => false],
        'negocio' => ['view' => true, 'manage' => true],
        // La cuenta de quien entra: su clave, su segundo factor y sus
        // sesiones. Nunca se le quita a nadie.
        'cuenta' => ['view' => true, 'manage' => true],
    ];

    public function mio(Request $request)
    {
        $negocio = $request->attributes->get('negocio');
        $negocios = $request->attributes->get('negocios');

        return response()->json([
            'business' => $this->ficha($negocio),
            'businesses' => $negocios->map(fn ($b) => [
                'busines_id' => (int) $b->busines_id,
                'name' => $b->name,
                'logo' => $b->logo,
                'state' => (int) $b->state,
                // Para que el selector de local pueda marcar el que está en
                // pausa: con dos tiendas, apagar una y olvidarlo es fácil.
                'accepting_orders' => (bool) $b->recibiendo_ahora,
            ])->values(),
            'role' => 'tendero',
            'permissions' => self::PERMISOS,
            'stats' => $this->resumen((int) $negocio->busines_id),
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
     *    desaparecería del catálogo sin que nadie supiera por qué. Para parar
     *    un rato —lo que de verdad necesita— está `recibiendo()`, acá abajo.
     *
     * `NIT` y `razonSocial_DCD` sí se dejan: son los datos con los que sale su
     * nombre en el comprobante de venta, y quien los conoce es él.
     */
    public function actualizar(ActualizarMiNegocioRequest $request)
    {
        $negocio = $request->attributes->get('negocio');

        $datos = $request->validated();

        // Cadena vacía desde un formulario es «sin tope», no cero.
        if (array_key_exists('max_courier_cash', $datos) && $datos['max_courier_cash'] === '') {
            $datos['max_courier_cash'] = null;
        }

        $negocio->fill($datos)->save();

        return response()->json([
            'message' => 'Datos de tu negocio actualizados.',
            'business' => $this->ficha($negocio->fresh()),
        ]);
    }

    /**
     * «RECIBIENDO PEDIDOS»: PARAR SIN DARSE DE BAJA.
     *
     * Endpoint propio y no un campo más de `actualizar()`, por dos motivos:
     *
     *  · Se pulsa desde la barra del panel, con un cliente delante, y
     *    `actualizar()` exige `name` y reenvía la ficha entera. Un interruptor
     *    que obliga a mandar el formulario completo falla justo cuando hay
     *    prisa — y si de paso pisa un campo a medio editar, peor.
     *  · Deja el registro de auditoría legible: «apagó la tienda» es un hecho
     *    distinto de «corrigió su dirección».
     *
     * NO ES `state`. Ver la nota de `actualizar()`: el alta y la baja son de
     * la plataforma. Esto es del tendero y se espera que cambie varias veces
     * al día.
     */
    public function recibiendo(RecibirPedidosRequest $request)
    {
        $negocio = $request->attributes->get('negocio');

        // `boolean` acepta 1/0, "1"/"0" y true/false, que es lo que mandan un
        // interruptor del panel y un `fetch` a mano sin ponerse de acuerdo.
        $datos = $request->validated();

        $recibe = (bool) $datos['accepting_orders'];

        $negocio->accepting_orders = $recibe;
        // Encender borra la hora: si no, queda una pausa vencida guardada que
        // la siguiente lectura tendría que explicar.
        /*
         * El panel y la app mandan la hora en UTC (toISOString: «…T20:00Z»).
         * El cast `datetime` la guardaría tal cual, como si fuera hora de
         * Colombia, y la pausa duraría cinco horas de más. Se pasa a la zona
         * del servidor antes de guardarla.
         */
        $hasta = $datos['accepting_until'] ?? null;
        $negocio->accepting_until = $recibe || ! $hasta
            ? null
            : Carbon::parse($hasta)->setTimezone(config('app.timezone'));
        $negocio->accepting_changed_at = now();
        $negocio->save();

        return response()->json([
            /*
             * El mensaje dice QUÉ PASA, no «guardado». Apagar la tienda es la
             * única acción de este panel que deja de ganar plata mientras está
             * puesta, y quien la pulsa tiene que saberlo sin pensarlo.
             */
            'message' => $this->mensajeDePausa($negocio),
            'business' => $this->ficha($negocio->fresh()),
        ]);
    }

    /** Lo que se le dice al tendero después de tocar el interruptor. */
    private function mensajeDePausa($negocio): string
    {
        if ($negocio->accepting_orders) {
            return 'Tu negocio volvió a recibir pedidos.';
        }

        if ($negocio->accepting_until === null) {
            return 'Tu negocio dejó de recibir pedidos. No te va a entrar ninguno hasta que la vuelvas a encender.';
        }

        // La hora, dicha como se dice en Barranquilla. `translatedFormat` usa
        // el idioma de la aplicación, que es español.
        $hora = $negocio->accepting_until->timezone(config('app.timezone'))->translatedFormat('g:i a');

        return "Tu negocio queda en pausa hasta las {$hora}. Se enciende sola.";
    }

    private function ficha($b): array
    {
        return (new MiNegocioResource($b))->resolve();
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

        // Como rango y no `whereDate`: medido con EXPLAIN sobre esta misma
        // consulta, 507 filas abiertas contra 21. Ver `FiltroDeFechas`.
        $hoy = FiltroDeFechas::unDia($base(), 'sale_date', now());

        return [
            // Los que esperan que alguien pulse "Aceptar". Es la cifra que
            // manda en este panel: mientras no baje a cero, hay gente
            // esperando sin saber si su pedido fue visto.
            'nuevos' => (int) $base()->where('state', 1)->count(),
            // Desde cuándo espera el más viejo de los que nadie ha aceptado.
            // La franja de aviso del panel dice cuánto le queda antes de los
            // treinta minutos, y sin esta fecha ese reloj no se puede contar:
            // el panel solo sabe cuántos hay, no desde cuándo.
            'espera_desde' => $base()->where('state', 1)->min('sale_date'),
            'en_curso' => (int) $base()->whereIn('state', [2, 3])->count(),
            'hoy' => (int) (clone $hoy)->count(),
            // Solo el subtotal: el domicilio no es del negocio, es del
            // domiciliario. Sumar el total le enseñaría una venta más alta que
            // la que se le va a liquidar.
            'venta_hoy' => (float) (clone $hoy)->whereIn('state', [2, 3, 4])->sum(DB::raw('subtotal - discount')),
        ];
    }

    /**
     * EL LOGO DE LA TIENDA, desde su propio panel.
     *
     * Antes solo lo cambiaba el equipo. Es lo primero que ve el vecino en la
     * app y la tienda es quien tiene la foto: pedirla por WhatsApp para que
     * otro la suba era el paso que sobraba. Solo imágenes —un PDF de logo no
     * se puede pintar en la app— y queda apuntado en `business.logo`, que es
     * lo que lee la app, igual que cuando lo sube el equipo.
     */
    public function logo(SubirLogoRequest $request, BusinessMediaService $medios)
    {
        $negocioId = (int) $request->attributes->get('busines_id');

        if (! $medios->configurado()) {
            return response()->json([
                'message' => 'No se pudo guardar el logo: el almacenamiento de archivos no está configurado.',
            ], 503);
        }

        $negocio = DB::table('business')->where('busines_id', $negocioId)->first(['busines_id', 'name']);

        try {
            $subido = $medios->subir($negocio, $request->file('logo'), 'logo');
        } catch (RuntimeException $e) {
            // `BusinessMediaService` solo lanza `RuntimeException`, con texto
            // para el tendero. Una averia de R2 no es un logo mal puesto.
            return response()->json(['message' => 'No se pudo guardar el logo. '.$e->getMessage()], 422);
        }

        DB::table('business')->where('busines_id', $negocioId)->update(['logo' => $subido['url']]);

        return response()->json(['message' => 'Logo actualizado.', 'logo' => $subido['url']], 201);
    }
}
