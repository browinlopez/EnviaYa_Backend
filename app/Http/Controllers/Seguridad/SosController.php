<?php

declare(strict_types=1);

namespace App\Http\Controllers\Seguridad;

use App\Http\Controllers\Controller;
use App\Http\Requests\Seguridad\LanzarSosRequest;
use App\Models\Domiciliary;
use App\Models\Operacion\SafetyIncident;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * EL BOTÓN DE SOS.
 *
 * Quien reparte y quien atiende un local están solos cuando pasa algo, y hasta
 * ahora lo contaban por WhatsApp: sin hora, sin sitio y sin que quedara en
 * ninguna parte. Esto lo deja en `safety_incidents`, que existe exactamente
 * para eso y ya tiene su página en el panel.
 *
 * ────────────────────────────────────────────────────────────────────────
 * EL AVISO SALE PRIMERO Y SE PREGUNTA DESPUÉS.
 *
 * Al completar los tres segundos la app manda YA, con motivo «otro» y sin
 * detalle. Sólo entonces aparece la hoja «¿Qué pasó?» y, si la tocan,
 * `afinar()` cambia el motivo. Si no la tocan, el aviso queda igual de
 * mandado. Dos pasos antes de que salga nada es un paso de más.
 *
 * Por eso aquí NADA es obligatorio: ni el motivo, ni las coordenadas. Un 422
 * por un campo que falta sería un SOS que no llegó por culpa de la
 * validación.
 * ────────────────────────────────────────────────────────────────────────
 *
 * APRETAR DOS VECES NO SON DOS EMERGENCIAS. Quien está asustado vuelve a
 * apretar, y tres filas iguales en el panel no informan mejor: esconden cuál
 * es la buena. Dentro de la ventana de abajo el segundo envío NO crea nada:
 * refresca la posición del aviso que ya existe —que es lo único que cambió,
 * porque la persona se movió— y devuelve ese mismo. Es lo que pide AGENTS.md
 * §18 para un POST con efecto que el cliente puede reintentar.
 */
class SosController extends Controller
{
    private const TENDERO = 2;

    private const DOMICILIARIO = 3;

    /** Minutos durante los cuales un segundo SOS es el mismo SOS. */
    private const VENTANA_REPETIDO = 3;

    /**
     * Minutos durante los cuales la app todavía puede afinar el motivo.
     *
     * Pasado ese rato el incidente es del equipo: alguien pudo leerlo, llamar
     * y escribir en él, y que el teléfono le cambiara el motivo por debajo
     * sería cambiarle el caso mientras lo atiende.
     */
    private const VENTANA_AFINAR = 15;

    public function lanzar(LanzarSosRequest $request)
    {
        $usuario = $request->user();
        $rol = (int) $usuario->rol;

        /*
         * El botón sólo existe en las dos pantallas de quien trabaja en la
         * calle o en el local. Un comprador que llegue aquí a mano se queda
         * fuera, no por desconfianza sino porque su emergencia no tiene a
         * dónde ir: nadie enruta incidentes de compradores, y un registro que
         * nadie mira es peor que no tenerlo, porque parece que sí.
         */
        if (! in_array($rol, [self::TENDERO, self::DOMICILIARIO], true)) {
            return response()->json([
                'message' => 'El SOS es para domiciliarios y tenderos. Si necesitas ayuda, escríbenos desde Soporte.',
            ], 403);
        }

        $datos = $request->validated();
        $lat = isset($datos['latitude']) ? (float) $datos['latitude'] : null;
        $lng = isset($datos['longitude']) ? (float) $datos['longitude'] : null;

        $repetido = SafetyIncident::query()
            ->where('reported_by', $usuario->user_id)
            ->where('state', SafetyIncident::ABIERTO)
            ->where('created_at', '>=', now()->subMinutes(self::VENTANA_REPETIDO))
            ->latest('id')
            ->first();

        if ($repetido) {
            if ($lat !== null && $lng !== null) {
                $repetido->forceFill([
                    'latitude' => $lat,
                    'longitude' => $lng,
                    'location' => $this->comoTexto($lat, $lng),
                ])->save();
            }

            return response()->json([
                'message' => 'Ya tenemos tu alerta. Seguimos pendientes.',
                'id' => $repetido->id,
            ], 200);
        }

        $incidente = SafetyIncident::create([
            'domiciliary_id' => $rol === self::DOMICILIARIO
                ? Domiciliary::where('user_id', $usuario->user_id)->value('domiciliary_id')
                : null,

            'business_id' => $rol === self::TENDERO ? $this->tiendaDe((int) $usuario->user_id) : null,

            'occurred_at' => now(),
            'type' => $datos['type'] ?? 'otro',

            /* Gravedad fija: no la manda el teléfono. Mantener tres segundos
               un botón rojo ya es la declaración. */
            'severity' => 'grave',
            'state' => SafetyIncident::ABIERTO,

            'latitude' => $lat,
            'longitude' => $lng,
            /*
             * Y LAS MISMAS COORDENADAS EN TEXTO, en `location`. La página de
             * Incidentes del panel pinta esa columna bajo el título «Lugar» y
             * no sabe nada de `latitude`/`longitude`. Sin esto, el SOS saldría
             * en la lista con el lugar en blanco, que es justo el dato por el
             * que se abre.
             */
            'location' => $this->comoTexto($lat, $lng),

            'description' => $this->primerRelato($usuario),
            'reported_by' => $usuario->user_id,
        ]);

        return response()->json([
            'message' => 'Alerta enviada.',
            'id' => $incidente->id,
        ], 201);
    }

    /**
     * El motivo, cuando la persona lo elige en la hoja que sale después.
     */
    public function afinar(Request $request, $id)
    {
        $incidente = SafetyIncident::findOr($id, fn () => abort(404, 'Esa alerta no existe.'));

        /* Sólo la suya. Sin esto bastaría con probar números para cambiarle el
           motivo a la emergencia de otra persona. */
        if ((int) $incidente->reported_by !== (int) $request->user()->user_id) {
            return response()->json(['message' => 'Esa alerta no es tuya.'], 403);
        }

        if ($incidente->state !== SafetyIncident::ABIERTO
            || $incidente->created_at?->lt(now()->subMinutes(self::VENTANA_AFINAR))) {
            return response()->json([
                'message' => 'Esa alerta ya está siendo atendida.',
            ], 409);
        }

        $datos = $request->validate([
            'type' => 'required|in:'.implode(',', SafetyIncident::MOTIVOS_SOS),
        ]);

        $incidente->forceFill([
            'type' => $datos['type'],
            'description' => $incidente->description."\nMotivo indicado: ".$datos['type'].'.',
        ])->save();

        return response()->json(['message' => 'Gracias. Ya lo sabemos.']);
    }

    /**
     * Qué se sabe en el momento del aviso, que es poco y por eso se escribe.
     *
     * `description` no admite nulos y el panel lo enseña como el relato del
     * incidente. Dejarlo vacío haría que el caso más urgente fuera el único
     * sin texto; esto al menos dice quién, desde dónde y que todavía no hay
     * motivo. El teléfono sale aquí porque es lo primero que necesita quien lo
     * atienda: para llamar no hay que abrir otra pantalla.
     */
    private function primerRelato($usuario): string
    {
        $telefono = trim((string) $usuario->phone) !== '' ? $usuario->phone : 'sin teléfono en la cuenta';

        return 'SOS desde la app. '.$usuario->name.' ('.$telefono.'). '
            .'Todavía sin motivo: el aviso sale antes de preguntar.';
    }

    private function comoTexto(?float $lat, ?float $lng): ?string
    {
        if ($lat === null || $lng === null) {
            return null;
        }

        return number_format($lat, 6, '.', '').', '.number_format($lng, 6, '.', '');
    }

    /**
     * La tienda del tendero.
     *
     * Misma consulta que usa `AyudaDesdeLaAppController` para saber si un
     * pedido es de su tienda. `value()` y no `pluck()`: un tendero con dos
     * locales manda el SOS desde uno, y el panel necesita un número, no una
     * lista. Cuál de los dos lo dirá él al llamar.
     */
    private function tiendaDe(int $userId): ?int
    {
        $id = DB::table('owner_busines as ob')
            ->join('owner as o', 'o.owner_id', '=', 'ob.owner_id')
            ->where('o.user_id', $userId)
            ->value('ob.busines_id');

        return $id !== null ? (int) $id : null;
    }
}
