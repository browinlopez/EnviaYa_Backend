<?php

declare(strict_types=1);

namespace App\Http\Controllers\Domiciliary;

use App\Http\Controllers\Controller;
use App\Models\Order\OrdersSales;
use App\Services\EvidenciaDeEntrega;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * ==========================================================================
 * LA FOTO DE QUE SE ENTREGÓ.
 *
 * Una entrega era la palabra del domiciliario y nada más. Cuando el cliente
 * llama diciendo que no le llegó, no hay nada que mirar, y la discusión la
 * pierde siempre quien no tiene cómo probarlo.
 *
 * --------------------------------------------------------------------------
 * LA RUTA NO LLEVA IDENTIFICADOR DE DOMICILIARIO, igual que el acuerdo y el
 * efectivo: sale de la SESIÓN. Con un parámetro, cualquiera podría colgarle
 * una foto a la entrega de otro —y una foto de la puerta de una casa, con
 * lo que eso enseña, no es un archivo cualquiera—.
 *
 * Y se comprueba que el pedido sea SUYO: no basta con ser domiciliario.
 */
class EvidenciaDeEntregaController extends Controller
{
    public function __construct(private EvidenciaDeEntrega $evidencias) {}

    /** Sube la foto de una entrega. */
    public function guardar(Request $request, int $id)
    {
        $request->validate([
            /*
             * `image` ya exige que sea una imagen de verdad y no un .php con
             * otro nombre. 4 MB es el tope del servicio: esto se sube desde
             * la calle, con la red del teléfono y el cliente esperando.
             */
            'foto' => 'required|image|mimes:jpg,jpeg,png,webp|max:4096',
        ], [
            'foto.required' => 'Falta la foto.',
            'foto.image' => 'Eso no es una imagen.',
            'foto.max' => 'La foto supera los 4 MB. Vuelve a tomarla con menos calidad.',
        ]);

        $pedido = $this->suyo($request, $id);

        if (! $pedido) {
            /*
             * 404 y no 403: confirmar que ese pedido existe pero es de otro
             * ya dice algo del trabajo del vecino, igual que al desvincular
             * un domiciliario de una tienda.
             */
            return response()->json(['message' => 'Ese pedido no es tuyo.'], 404);
        }

        try {
            $guardada = $this->evidencias->guardar($pedido, $request->file('foto'), (int) $request->user()->user_id);
        } catch (RuntimeException $e) {
            /*
             * 422 y con el motivo: el almacenamiento sin configurar y la foto
             * demasiado grande son cosas que la pantalla puede contar, no
             * errores del servidor que el domiciliario deba tragar como un
             * «algo salió mal».
             */
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['data' => $guardada], 201);
    }

    /** La foto de una entrega, si la hay. */
    public function ver(Request $request, int $id)
    {
        if (! $this->suyo($request, $id)) {
            return response()->json(['message' => 'Ese pedido no es tuyo.'], 404);
        }

        return response()->json(['data' => $this->evidencias->de($id)]);
    }

    /**
     * El pedido, sólo si lo lleva quien pregunta.
     *
     * Se admite ENTREGADO además de los activos: la foto puede llegar tarde
     * --en la calle la mitad de las subidas fallan-- y obligar a que llegue
     * antes de cerrar la entrega dejaría al domiciliario plantado en la
     * puerta esperando a una barra de progreso.
     */
    private function suyo(Request $request, int $orderId): ?OrdersSales
    {
        $domiciliarioId = DB::table('domiciliary')
            ->where('user_id', $request->user()->user_id)
            ->value('domiciliary_id');

        if (! $domiciliarioId) {
            return null;
        }

        return OrdersSales::where('orderSales_id', $orderId)
            ->where('domiciliary_id', $domiciliarioId)
            ->first();
    }
}
