<?php

namespace App\Http\Controllers\Negocio;

use App\Http\Controllers\Controller;
use App\Models\Business\BusinessUserAffiliation;
use App\Models\User;
use App\Services\CreditoDeTienda;
use Illuminate\Http\Request;

/**
 * LOS CLIENTES AFILIADOS A LA TIENDA
 *
 * Afiliar es lo que convierte a un comprador cualquiera en cliente de ESTA
 * tienda: es quien recibe sus promociones y a quien se le puede fiar.
 *
 * POR QUÉ NO SE REUSAN LAS RUTAS DE LA APP. `/businesses/affiliations/*`
 * reciben el `busines_id` en el cuerpo. Están defendidas —comprueban que el
 * negocio sea suyo—, pero el panel trabaja al revés: el negocio sale de la
 * sesión y de la cabecera `X-Negocio`, y ninguna pantalla puede olvidarse de
 * mandarlo. Mezclar las dos formas en el mismo panel es como se cuela el día
 * de mañana una pantalla que afilia al negocio equivocado.
 *
 * Y NO SE LES PONE EL MIDDLEWARE A LAS DE LA APP, que sería lo cómodo: ese
 * middleware pisa el `busines_id` del cuerpo con el de la sesión, y un tendero
 * con dos locales que no mande la cabecera acabaría afiliando gente al local
 * que no era. Sin fallar, que es lo peor.
 */
class ClientesDelNegocioController extends Controller
{
    public function __construct(private readonly CreditoDeTienda $creditos)
    {
    }

    /**
     * Buscar un comprador por su teléfono, para afiliarlo.
     *
     * SE DEVUELVE LO MÍNIMO. Acá hay una persona que no es cliente de esta
     * tienda —todavía— y a la que se está buscando por un número de teléfono:
     * lo justo para reconocerla y nada más. Su dirección, su rol o cuándo
     * aceptó la política no hacen falta para decidir si es la que se busca.
     */
    public function buscar(Request $request)
    {
        $datos = $request->validate([
            'telefono' => 'required|string|min:7|max:40',
        ]);

        $negocioId = (int) $request->attributes->get('busines_id');

        $usuario = User::where('phone', $datos['telefono'])
            // Rol 1 y con ficha de comprador: es a quien se le puede vender.
            ->where('rol', 1)
            ->whereHas('buyer')
            ->first(['user_id', 'name', 'email', 'phone']);

        if (!$usuario) {
            return response()->json([
                'message' => 'No encontramos ningún comprador con ese número.',
                'cliente' => null,
            ], 404);
        }

        return response()->json([
            'cliente' => [
                'user_id'  => (int) $usuario->user_id,
                'name'     => $usuario->name,
                'email'    => $usuario->email,
                'phone'    => $usuario->phone,
                'afiliado' => BusinessUserAffiliation::where('busines_id', $negocioId)
                    ->where('user_id', $usuario->user_id)
                    ->exists(),
            ],
        ]);
    }

    /** Afiliarlo a esta tienda. Repetirlo no es un error: ya estaba. */
    public function afiliar(Request $request, int $userId)
    {
        $negocioId = (int) $request->attributes->get('busines_id');

        $existe = User::where('user_id', $userId)->where('rol', 1)->whereHas('buyer')->exists();

        if (!$existe) {
            return response()->json(['message' => 'Esa cuenta no es la de un comprador.'], 404);
        }

        BusinessUserAffiliation::firstOrCreate([
            'busines_id' => $negocioId,
            'user_id'    => $userId,
        ]);

        return response()->json(['message' => 'Ya es cliente de tu tienda.'], 201);
    }

    /**
     * Quitarlo de la lista.
     *
     * NO SE BORRA SU DEUDA NI SU CUPO. Desafiliar es dejar de mandarle
     * promociones y de poder fiarle de nuevo, no perdonarle lo que debe: el
     * panel de crédito lo sigue enseñando justamente para que una deuda no
     * desaparezca de la vista por haber tocado el botón equivocado.
     *
     * Por eso la respuesta dice cuánto quedaba debiendo: la pantalla lo avisa
     * antes de hacerlo.
     */
    public function desafiliar(Request $request, int $userId)
    {
        $negocioId = (int) $request->attributes->get('busines_id');

        BusinessUserAffiliation::where('busines_id', $negocioId)
            ->where('user_id', $userId)
            ->delete();

        $cupo = $this->creditos->cupo($negocioId, $userId);

        return response()->json([
            'message' => ((float) ($cupo['usado'] ?? 0)) > 0
                ? 'Lo quitamos de tus clientes. Su deuda sigue viva y la ves en Fiado.'
                : 'Lo quitamos de tus clientes.',
            'debia'   => (float) ($cupo['usado'] ?? 0),
        ]);
    }
}
