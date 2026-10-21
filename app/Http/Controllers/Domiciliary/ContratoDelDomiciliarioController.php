<?php

namespace App\Http\Controllers\Domiciliary;

use App\Http\Controllers\Controller;
use App\Services\ContratoService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * EL ACUERDO DE VINCULACIÓN, FIRMADO POR SU PROPIO DUEÑO
 *
 * Hasta ahora el acuerdo solo se podía firmar desde el panel de
 * administración: alguien del equipo abría la ficha del domiciliario, le pasaba
 * el computador y él trazaba la firma ahí. Eso significa que para vincularse
 * había que coincidir físicamente con alguien del equipo, y que la firma de
 * una persona quedaba capturada desde la sesión de otra.
 *
 * Acá lo firma él, desde su teléfono, la primera vez que entra.
 *
 * TRES COSAS QUE ESTO TIENE QUE SOSTENER, porque es un documento legal:
 *
 *  1. QUE FIRME LO QUE LEYÓ. El texto que devuelve `GET` sale de la MISMA
 *     plantilla que produce el PDF, no de una copia. Dos redacciones del mismo
 *     acuerdo se desincronizan en la primera corrección que alguien haga de un
 *     lado solo.
 *
 *  2. QUE SE SEPA DE DÓNDE SALIÓ LA FIRMA. El pie del PDF dice si se capturó
 *     en el panel y por quién, o en la aplicación por el propio trabajador y
 *     desde qué dirección IP. Sin eso, los dos caminos producen documentos
 *     indistinguibles.
 *
 *  3. QUE NO SE PUEDA REFIRMAR POR ENCIMA. Un acuerdo ya firmado no se vuelve
 *     a firmar desde acá: cambiarlo es cosa del equipo, con su constancia. Si
 *     se dejara, bastaría con entrar otra vez para reemplazar el documento que
 *     respalda una vinculación.
 */
class ContratoDelDomiciliarioController extends Controller
{
    public function __construct(private readonly ContratoService $contratos)
    {
    }

    /**
     * Su ficha de domiciliario, o null si quien pregunta no lo es.
     *
     * Sale de la SESIÓN y nunca de la ruta: con un identificador en la
     * dirección, cualquiera podría leer —o firmar— el acuerdo de otro.
     */
    private function suyo(Request $request): ?object
    {
        return DB::table('domiciliary as d')
            ->leftJoin('user as u', 'u.user_id', '=', 'd.user_id')
            ->where('d.user_id', $request->user()->user_id)
            ->first([
                'd.domiciliary_id', 'd.user_id', 'd.document',
                'd.contract_signed_at', 'd.contract_city', 'd.contract_media_id',
                'u.name',
            ]);
    }

    /** El acuerdo para leerlo, y si ya está firmado. */
    public function mostrar(Request $request)
    {
        $d = $this->suyo($request);

        if (!$d) {
            return response()->json(['message' => 'Tu cuenta no es la de un domiciliario.'], 403);
        }

        return response()->json([
            'firmado'    => $d->contract_signed_at !== null,
            'firmado_el' => $d->contract_signed_at,
            'ciudad'     => $d->contract_city,
            'documento'  => $d->document,
            'nombre'     => $d->name,
            /*
             * Hace falta ANTES de firmar: el acuerdo lleva la cédula impresa y
             * firmarlo sin ella no tiene sentido. La pantalla la pide cuando
             * falta en vez de dejar que el envío reviente.
             */
            'falta_documento' => empty($d->document),
            'texto'      => $this->contratos->comoTexto($d),
        ]);
    }

    /** Firmarlo. */
    public function firmar(Request $request)
    {
        $datos = $request->validate([
            'signature' => 'required|string',
            'city'      => 'required|string|max:120',
            // Se puede escribir la cédula en el mismo paso, como en el panel.
            'document'  => 'sometimes|nullable|string|max:50',
            /*
             * La aceptación va aparte de la firma, y tiene que venir en true.
             *
             * Trazar una firma es un gesto; decir «leí y acepto» es la
             * declaración. Son dos actos distintos y el acuerdo se apoya en
             * los dos.
             */
            'acepta'    => 'required|accepted',
        ]);

        $d = $this->suyo($request);

        if (!$d) {
            return response()->json(['message' => 'Tu cuenta no es la de un domiciliario.'], 403);
        }

        if ($d->contract_signed_at !== null) {
            return response()->json([
                'message' => 'Tu acuerdo ya está firmado. Si hay que cambiarlo, escríbenos.',
                'reason'  => 'ya_firmado',
            ], 409);
        }

        $documentoNuevo = $datos['document'] ?? null;
        $d->document = $documentoNuevo ?: $d->document;

        if (!$d->document) {
            return response()->json([
                'message' => 'Escribe tu número de documento: el acuerdo lo lleva impreso.',
                'reason'  => 'falta_documento',
            ], 422);
        }

        try {
            $resultado = $this->contratos->generar(
                $d,
                $datos['signature'],
                $datos['city'],
                // Sin administrador: no hay ninguno del otro lado.
                null,
                null,
                'en la aplicación por el propio trabajador, desde la dirección '
                    . ($request->ip() ?: 'desconocida'),
            );
        } catch (\Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        if ($documentoNuevo) {
            DB::table('domiciliary')
                ->where('domiciliary_id', $d->domiciliary_id)
                ->update(['document' => $documentoNuevo]);
        }

        return response()->json([
            'message'   => 'Listo. Tu acuerdo quedó firmado y guardado.',
            'firmado_el' => $resultado['signed_at'],
            'url'       => $resultado['url'],
        ], 201);
    }
}
