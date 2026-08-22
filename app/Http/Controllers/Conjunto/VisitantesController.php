<?php

namespace App\Http\Controllers\Conjunto;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Control de acceso de quien NO es usuario de la plataforma.
 *
 * Visitas, personal de servicio, contratistas, mudanzas, y los domicilios de
 * otras plataformas. Todo eso pasa por la portería del conjunto y hasta ahora
 * no dejaba rastro: el panel sólo sabía de domiciliarios de EnviaYa, que son
 * una fracción del movimiento real del edificio.
 *
 * NO SE CREA NINGUNA CUENTA. Quien viene hoy a ver a su hermana no es —ni
 * tiene por qué ser— usuario de VeciPa'Ya. Sus datos viven en la entrada, que
 * es el hecho que importa: entró, a esta hora, a este apartamento, y lo
 * autorizó fulano. Una tabla de visitantes se llenaría de filas de un solo uso
 * con nombres mal escritos que nadie deduplica.
 *
 * Por lo mismo NO hay verificación posible: acá no se comprueba nada contra el
 * sistema, se ANOTA lo que el celador ve. Quien decide si pasa es él, con el
 * citófono en la mano. El panel le da el libro, no el criterio.
 */
class VisitantesController extends Controller
{
    private const CLASES = ['visitante', 'servicio', 'domicilio_externo'];

    public function registrar(Request $request)
    {
        $complexId = (int) $request->attributes->get('complex_id');

        $datos = $request->validate([
            'kind'             => 'required|string|in:' . implode(',', self::CLASES),
            'visitor_name'     => 'required|string|max:120',
            'visitor_document' => 'nullable|string|max:40',
            'visitor_phone'    => 'nullable|string|max:40',
            'visitor_company'  => 'nullable|string|max:120',
            'vehicle_plate'    => 'nullable|string|max:20',
            'tower'            => 'nullable|string|max:30',
            'apartment'        => 'nullable|string|max:30',
            'authorized_by'    => 'nullable|string|max:120',
            'notes'            => 'nullable|string|max:1000',
        ]);

        /*
         * El conjunto puede exigir que quede CONSTANCIA de quién dio permiso.
         *
         * Ojo con el nombre de la columna, `require_authorization`: esto no
         * autoriza nada. Obliga al celador a escribir un nombre, y nada
         * comprueba que esa persona dijera que sí — es el registro de una
         * llamada al citófono. Una autorización de verdad exige que el
         * residente actúe, y eso está diseñado y sin construir.
         *
         * Se comprueba acá y no en el navegador: la regla es del edificio y
         * tiene que valer aunque la petición venga de otro sitio.
         */
        $exige = (bool) DB::table('residential_complexes')
            ->where('complex_id', $complexId)
            ->value('require_authorization');

        if ($exige && empty($datos['authorized_by'])) {
            return response()->json([
                'message' => 'Este conjunto exige dejar constancia de quién dio permiso. Anota quién autorizó.',
                'errors'  => ['authorized_by' => ['Falta anotar quién dio permiso.']],
            ], 422);
        }

        $id = DB::table('complex_entries')->insertGetId($datos + [
            'complex_id'     => $complexId,
            // Sin domiciliario y sin método de identificación del sistema: no
            // hay nada que verificar, sólo lo que el celador anotó.
            'domiciliary_id' => null,
            'method'         => 'manual',
            'orders_count'   => 0,
            'registered_by'  => $request->user()->user_id,
            'created_at'     => now(),
            'updated_at'     => now(),
        ]);

        return response()->json([
            'message'  => 'Entrada registrada.',
            'entry_id' => $id,
        ], 201);
    }

    /**
     * Marcar la salida.
     *
     * Sin esto el libro no puede responder «¿cuánta gente hay adentro ahora?»,
     * que es la pregunta que más se hace en una portería. Sirve para las dos
     * clases de entrada: un domiciliario también sale.
     */
    public function salida(Request $request, int $id)
    {
        $complexId = (int) $request->attributes->get('complex_id');

        $entrada = DB::table('complex_entries')
            ->where('id', $id)
            ->where('complex_id', $complexId)
            ->first();

        // 404 y no 403: confirmar que existe pero es de otro conjunto ya sería
        // decir algo del edificio del vecino.
        if (!$entrada) {
            return response()->json(['message' => 'Esa entrada no es de tu conjunto.'], 404);
        }

        if ($entrada->exited_at) {
            /*
             * No se pisa la salida anterior. La primera es la que ocurrió; una
             * segunda pulsación —por duda o por doble clic— reescribiría la
             * hora y falsearía cuánto tiempo estuvo adentro.
             */
            return response()->json([
                'message' => 'Esa salida ya estaba registrada.',
                'exited_at' => $entrada->exited_at,
            ], 409);
        }

        DB::table('complex_entries')->where('id', $id)->update([
            'exited_at'          => now(),
            'exit_registered_by' => $request->user()->user_id,
            'updated_at'         => now(),
        ]);

        return response()->json(['message' => 'Salida registrada.']);
    }

    /** Quién está adentro ahora mismo: entró y no ha salido. */
    public function adentro(Request $request)
    {
        $complexId = (int) $request->attributes->get('complex_id');

        $filas = DB::table('complex_entries as e')
            ->where('e.complex_id', $complexId)
            ->whereNull('e.exited_at')
            /*
             * Sólo las de hoy. Una entrada de hace tres días sin salida no es
             * alguien que lleva tres días adentro: es una salida que nadie
             * anotó. Arrastrarlas haría crecer el conteo para siempre y la
             * cifra dejaría de significar nada.
             */
            ->whereDate('e.created_at', now()->toDateString())
            ->leftJoin('domiciliary as d', 'd.domiciliary_id', '=', 'e.domiciliary_id')
            ->leftJoin('user as u', 'u.user_id', '=', 'd.user_id')
            ->orderByDesc('e.created_at')
            ->get([
                'e.id', 'e.kind', 'e.method', 'e.created_at', 'e.orders_count',
                'e.tower', 'e.apartment', 'e.visitor_company', 'e.vehicle_plate',
                DB::raw('COALESCE(u.name, e.visitor_name) as nombre'),
                DB::raw('COALESCE(d.document, e.visitor_document) as documento'),
            ]);

        return response()->json(['data' => $filas]);
    }
}
