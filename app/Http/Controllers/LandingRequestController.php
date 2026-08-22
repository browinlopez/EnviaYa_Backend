<?php

namespace App\Http\Controllers;

use App\Models\Operacion\LandingRequest;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * LO QUE MANDAN LOS FORMULARIOS DE LA WEB PÚBLICA
 *
 * Seis formularios de la landing entran por acá: contacto, alta de comercio,
 * alta de domiciliario, alta de conjunto, "suma tu barrio" y eliminación de
 * cuenta. Hasta ahora ninguno guardaba nada —la web validaba, decía "¡Listo!"
 * y descartaba el mensaje—, así que cada tendero que pidió entrar se perdió.
 *
 * ES PÚBLICO Y NO PUEDE EXIGIR TOKEN
 *
 * Quien escribe todavía no tiene cuenta: ese es justamente el punto. Las
 * defensas son otras y están puestas en capas:
 *
 *  · Límite por IP en la ruta. Una persona envía una vez; seis por minuto
 *    deja margen para reintentar y corta el envío automático.
 *  · Trampa (honeypot). La web ya la filtra en el navegador, pero un script
 *    que llame directo a la API no pasa por ahí. Si viene rellena se responde
 *    que sí y no se guarda nada: un bot que recibe un error aprende a
 *    esquivar la trampa; uno que recibe éxito, no.
 *  · Autorización obligatoria. Sin el consentimiento marcado no se guarda,
 *    porque la Ley 1581 de 2012 no admite consentimiento tácito.
 *
 * LO QUE NO HACE
 *
 * No responde correos ni notifica a nadie. Guardar es lo que faltaba; avisar
 * es otra decisión, y montarla acá de paso significaría elegir por el equipo
 * a qué buzón llega cada tipo de solicitud.
 */
class LandingRequestController extends Controller
{
    /** Campos que ya tienen columna propia y no se repiten dentro de `payload`. */
    private const PROPIOS = [
        'nombre', 'contacto', 'barrio', 'mensaje',
        'tipo', 'motivo', 'origen', 'pagina',
        'consentimiento', 'politica_version', 'aceptado_en',
        'empresa', 'trampa',
    ];

    public function store(Request $request)
    {
        /*
         * La validación es deliberadamente laxa con los campos de cada
         * formulario y estricta con lo que sostiene la ley y la integridad de
         * la tabla. Rechazar un envío porque la web añadió una pregunta que el
         * servidor no conoce sería perder exactamente lo que este endpoint
         * existe para no perder.
         */
        $datos = $request->validate([
            'nombre'           => 'nullable|string|max:150',
            'contacto'         => 'required|string|max:150',
            'barrio'           => 'nullable|string|max:150',
            'mensaje'          => 'nullable|string|max:5000',
            'tipo'             => 'nullable|string|max:30',
            'motivo'           => 'nullable|string|max:60',
            'origen'           => 'nullable|string|max:40',
            'pagina'           => 'nullable|string|max:120',
            'consentimiento'   => 'accepted',
            'politica_version' => 'nullable|string|max:20',
            'aceptado_en'      => 'nullable|date',
        ], [
            'consentimiento.accepted' =>
                'Falta la autorización de tratamiento de datos.',
        ]);

        // La trampa. Se responde igual que en el caso bueno, a propósito.
        if (filled($request->input('empresa')) || filled($request->input('trampa'))) {
            Log::info('Solicitud de la web descartada por la trampa antispam', [
                'ip' => $request->ip(),
            ]);

            return response()->json(['message' => 'Solicitud recibida.'], 201);
        }

        $tipo = LandingRequest::tipoDesde($datos['tipo'] ?? null, $datos['motivo'] ?? null);

        $contacto = trim($datos['contacto']);

        $solicitud = LandingRequest::create([
            'code'           => LandingRequest::siguienteRadicado(),
            'type'           => $tipo,
            'origin'         => $datos['origen'] ?? null,
            'page'           => $datos['pagina'] ?? null,
            'name'           => $datos['nombre'] ?? null,
            'contact'        => $contacto,
            // Se guarda aparte solo si de verdad es un correo: así el panel
            // puede ofrecer "responder" sin adivinar si eso es un WhatsApp.
            'contact_email'  => filter_var($contacto, FILTER_VALIDATE_EMAIL) ? $contacto : null,
            'neighborhood'   => $datos['barrio'] ?? null,
            'message'        => $datos['mensaje'] ?? null,
            'payload'        => $this->extras($request),
            // Solo la eliminación tiene plazo comprometido: está prometido por
            // escrito en /eliminar-cuenta, que es la página que revisa Google
            // Play. Se fija al recibir y no se recalcula.
            'due_at'         => $tipo === 'eliminacion'
                ? now()->addWeekdays(LandingRequest::DIAS_ELIMINACION)
                : null,
            'policy_version' => $datos['politica_version'] ?? null,
            /*
             * El navegador manda la fecha en UTC (`toISOString`), y la base
             * guarda en la zona de la aplicación. Sin convertirla, una
             * autorización firmada a las 9 de la noche en Barranquilla se
             * guardaba como las 2 de la mañana del día siguiente: cinco horas
             * de diferencia en el dato que sirve justamente para demostrar
             * cuándo se autorizó.
             */
            'accepted_at'    => isset($datos['aceptado_en'])
                ? Carbon::parse($datos['aceptado_en'])->setTimezone(config('app.timezone'))
                : now(),
            'ip'             => $request->ip(),
            'user_agent'     => substr((string) $request->userAgent(), 0, 255),
        ]);

        return response()->json([
            'message' => 'Solicitud recibida.',
            'code'    => $solicitud->code,
        ], 201);
    }

    /**
     * Todo lo que mandó el formulario y no tiene columna propia.
     *
     * Es lo que cambia de un formulario a otro: el nombre del negocio y su
     * tipo, si el domiciliario trabaja en una tienda y en cuál, el nombre del
     * conjunto y la relación con él. Se recorta cada valor porque lo que entra
     * acá no pasó por una regla de longitud.
     */
    private function extras(Request $request): array
    {
        $extras = [];

        foreach ($request->all() as $clave => $valor) {
            if (in_array($clave, self::PROPIOS, true)) {
                continue;
            }

            if (is_scalar($valor) && filled($valor)) {
                $extras[substr((string) $clave, 0, 40)] = substr((string) $valor, 0, 500);
            }
        }

        return $extras;
    }
}
