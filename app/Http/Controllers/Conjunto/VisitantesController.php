<?php

declare(strict_types=1);

namespace App\Http\Controllers\Conjunto;

use App\Http\Controllers\Controller;
use App\Http\Requests\Conjunto\RegistrarVisitanteRequest;
use App\Models\Buyer\ResidentialComplex;
use App\Models\Conjunto\ComplexEntry;
use App\Models\Conjunto\ComplexStaff;
use App\Services\PulsoDelConjunto;
use App\Support\Enmascarar;
use App\Support\FiltroDeFechas;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Control de acceso de quien NO es usuario de la plataforma.
 *
 * Visitas, personal de servicio, contratistas, mudanzas, y los domicilios de
 * otras plataformas. Todo eso pasa por la portería del conjunto y hasta ahora
 * no dejaba rastro: el panel sólo sabía de domiciliarios de VeciPa'Ya, que son
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
    /*
     * PUBLICA porque la lee `RegistrarVisitanteRequest`, que es quien valida
     * el campo. El vocabulario es de este controlador; la regla que lo aplica
     * vive en el FormRequest y lo lee de aqui en vez de repetirlo.
     */
    public const CLASES = ['visitante', 'servicio', 'domicilio_externo'];

    public function registrar(RegistrarVisitanteRequest $request)
    {
        $complexId = (int) $request->attributes->get('complex_id');

        /*
         * Las reglas de campo viven en `RegistrarVisitanteRequest` —§04— y lo
         * que sigue aqui abajo NO se movio con ellas: pregunta a la base si
         * este conjunto exige constancia, y traerlo al FormRequest cambiaria
         * el texto de los dos mensajes. Esta explicado en esa clase.
         */
        $datos = $request->validated();

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
                'errors' => ['authorized_by' => ['Falta anotar quién dio permiso.']],
            ], 422);
        }

        $id = DB::table('complex_entries')->insertGetId($datos + [
            'complex_id' => $complexId,
            // Sin domiciliario y sin método de identificación del sistema: no
            // hay nada que verificar, sólo lo que el celador anotó.
            'domiciliary_id' => null,
            'method' => 'manual',
            'orders_count' => 0,
            'registered_by' => $request->user()->user_id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return response()->json([
            'message' => 'Entrada registrada.',
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

        $entrada = ComplexEntry::query()
            ->where('id', $id)
            ->where('complex_id', $complexId)
            ->first();

        // 404 y no 403: confirmar que existe pero es de otro conjunto ya sería
        // decir algo del edificio del vecino.
        if (! $entrada) {
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

        // Por el modelo y no con `DB::table`: así la salida queda en `audits`
        // con su valor anterior y el nuevo (ver `ComplexEntry`).
        $entrada->forceFill([
            'exited_at' => now()->toDateTimeString(),
            'exit_registered_by' => $request->user()->user_id,
        ])->save();

        return response()->json(['message' => 'Salida registrada.']);
    }

    /** Quién está adentro ahora mismo: entró y no ha salido. */
    public function adentro(Request $request)
    {
        $complexId = (int) $request->attributes->get('complex_id');
        $enmascarar = $request->attributes->get('complex_role') !== ComplexStaff::DUENO;

        /*
         * QUIEN LLEVA DEMASIADO. Pasadas las horas de alerta del conjunto (4
         * si no fijó otras) la entrada sale con `demorado: true` y cuántos
         * minutos lleva: un domiciliario que entró hace cinco horas o se fue
         * sin que nadie le marcara la salida o sigue adentro sin razón, y las
         * dos cosas hay que mirarlas.
         */
        $horas = ResidentialComplex::horasDeAlerta($complexId);
        $ahora = now();

        $filas = DB::table('complex_entries as e')
            ->where('e.complex_id', $complexId)
            ->whereNull('e.exited_at')
            /*
             * Sólo las de hoy. Una entrada de hace tres días sin salida no es
             * alguien que lleva tres días adentro: es una salida que nadie
             * anotó. Arrastrarlas haría crecer el conteo para siempre y la
             * cifra dejaría de significar nada.
             */
            // Como rango y no `whereDate`: ver `FiltroDeFechas`. Medido
            // sobre esta misma tabla, 1388 filas abiertas contra 1.
            ->tap(fn ($q) => FiltroDeFechas::unDia($q, 'e.created_at', now()))
            ->leftJoin('domiciliary as d', 'd.domiciliary_id', '=', 'e.domiciliary_id')
            ->leftJoin('user as u', 'u.user_id', '=', 'd.user_id')
            ->orderByDesc('e.created_at')
            ->get([
                'e.id', 'e.kind', 'e.method', 'e.created_at', 'e.orders_count',
                'e.tower', 'e.apartment', 'e.visitor_company', 'e.vehicle_plate', 'e.orders',
                DB::raw('COALESCE(u.name, e.visitor_name) as nombre'),
                DB::raw('COALESCE(d.document, e.visitor_document) as documento'),
            ])
            ->map(function ($e) use ($enmascarar, $horas, $ahora) {
                $e->destino = PulsoDelConjunto::destinoDe($e);
                unset($e->orders);

                $e->minutos_adentro = max(0, (int) floor(($ahora->getTimestamp() - strtotime((string) $e->created_at)) / 60));
                $e->demorado = $e->minutos_adentro >= $horas * 60;

                // Lo mismo que en el libro de entradas: el celador ve los
                // cuatro últimos, el administrador el dato completo.
                if ($enmascarar) {
                    $e->documento = Enmascarar::ultimos4($e->documento);
                }

                return $e;
            });

        return response()->json([
            'data' => $filas,
            'horas_alerta' => $horas,
            'demorados' => $filas->where('demorado', true)->count(),
        ]);
    }
}
