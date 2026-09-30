<?php

namespace App\Http\Controllers\Soporte;

use App\Http\Controllers\Controller;
use App\Models\Order\OrdersSales;
use App\Models\Operacion\Pqrs;
use Illuminate\Http\Request;

/**
 * PEDIR AYUDA DESDE LA APP.
 *
 * Hasta ahora, a quien le llegaba un pedido mal —o le cobraban dos veces, o el
 * domiciliario no aparecía— no tenía a dónde escribir: la app no ofrecía
 * ningún camino y la bandeja de PQRS del panel solo se llenaba desde adentro.
 * El reclamo terminaba en el WhatsApp personal de alguien, sin plazo, sin
 * radicado y sin que nadie más pudiera retomarlo.
 *
 * Esto radica en la MISMA bandeja que usa el equipo, con su código y su plazo.
 * El canal queda marcado como `app` para poder medir por dónde llega el ruido.
 *
 * Quien escribe está autenticado, así que el nombre, el correo y el teléfono
 * salen de su cuenta y no de lo que teclee: un reclamo que dice ser de otra
 * persona no sirve para nada.
 */
class AyudaDesdeLaAppController extends Controller
{
    /** Lo que la app ofrece elegir. «Felicitación» no: eso es una reseña. */
    private const TIPOS = 'peticion,queja,reclamo,sugerencia';

    public function store(Request $request)
    {
        $datos = $request->validate([
            'type'        => 'required|in:' . self::TIPOS,
            'subject'     => 'required|string|max:200',
            'description' => 'required|string|max:4000',
            'order_id'    => 'sometimes|nullable|integer|exists:orderssales,orderSales_id',
        ]);

        $usuario = $request->user();

        /*
         * El pedido, solo si es suyo.
         *
         * Sin esto bastaría con probar números para colgar un reclamo del
         * pedido de otra persona, y el caso llegaría al panel señalando a una
         * tienda y a un domiciliario que no tuvieron nada que ver.
         */
        $pedido = null;
        if (! empty($datos['order_id'])) {
            $pedido = OrdersSales::with('buyer')->find($datos['order_id']);

            if (! $pedido || (int) ($pedido->buyer->user_id ?? 0) !== (int) $usuario->user_id) {
                return response()->json([
                    'message' => 'Ese pedido no es tuyo.',
                ], 403);
            }
        }

        $prioridad = $datos['type'] === 'reclamo' ? 'alta' : 'media';

        $pqrs = Pqrs::create([
            'code'           => Pqrs::siguienteRadicado(),
            'type'           => $datos['type'],
            'channel'        => 'app',
            'priority'       => $prioridad,
            'user_id'        => $usuario->user_id,
            'order_id'       => $pedido?->orderSales_id,
            // Con el pedido delante, el caso ya sabe a qué tienda y a qué
            // domiciliario mirar: es la mitad del trabajo de quien lo atienda.
            'business_id'    => $pedido?->busines_id,
            'domiciliary_id' => $pedido?->domiciliary_id,
            'contact_name'   => $usuario->name,
            'contact_email'  => $usuario->email,
            'contact_phone'  => $usuario->phone,
            'subject'        => $datos['subject'],
            'description'    => $datos['description'],
            'state'          => 0,
            'due_at'         => now()->addDays(Pqrs::PLAZOS[$prioridad]),
        ]);

        return response()->json([
            'message' => 'Recibimos tu mensaje. Te respondemos al correo de tu cuenta.',
            'code'    => $pqrs->code,
        ], 201);
    }

    /**
     * Lo que esta persona ya escribió, para no repetir el reclamo.
     *
     * Sin esta lista, quien no recibe respuesta en el mismo día vuelve a
     * radicar lo mismo, y el equipo acaba con tres casos del mismo problema.
     */
    public function index(Request $request)
    {
        $casos = Pqrs::where('user_id', $request->user()->user_id)
            ->latest('created_at')
            ->limit(20)
            ->get(['code', 'type', 'subject', 'state', 'created_at', 'resolved_at', 'resolution']);

        return response()->json(['data' => $casos]);
    }
}
