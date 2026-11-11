<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Deja rastro de cada escritura autenticada de la API (quién hizo qué,
 * desde dónde y con qué resultado) en el canal de log 'audit'.
 *
 * Complementa la auditoría de modelos (OwenIt): esa registra los cambios
 * en los datos; esta registra la petición aunque no cambie nada (intentos
 * rechazados, validaciones fallidas, etc.). Los GET no se registran, para no
 * generar ruido, y las rutas de escritura de alto volumen se excluyen
 * explícitamente.
 *
 * LO QUE ESTE REGISTRO **NO** PUEDE DECIR. En el grupo de rutas va detrás
 * de `Authenticate:sanctum`, así que una llamada sin token válido muere en
 * auth y no llega aquí. La ausencia de una línea significa «ningún cliente
 * autenticado», no «ningún cliente». Para la pregunta de si una ruta está
 * muerta eso basta —la app publicada llama con token—, pero hay que leerlo
 * sabiéndolo.
 */
class AuditApiRequest
{
    private const WRITE_METHODS = ['POST', 'PUT', 'PATCH', 'DELETE'];

    /**
     * Rutas de escritura de alto volumen que no aportan al rastro de
     * auditoría (pings periódicos, no acciones del usuario).
     */
    private const EXCLUDED_PATHS = [
        'v1/orders/geolocation',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        /*
         * SOLO ESCRITURAS, y volvio a ser asi de simple.
         *
         * Hubo nueve GET con pase —`WATCHED_READS`— mientras se decidia si
         * retirarlos: el filtro de verbo los descartaba, y sin registro la
         * decision no tenia con que sostenerse. Las nueve se retiraron el 11
         * de noviembre de 2026 con las otras 22 rutas sin llamante, asi que
         * la excepcion se fue con la decision que la justificaba. Si mañana
         * hace falta vigilar otra lectura, se vuelve a poner una lista
         * temporal igual, con la misma fecha de caducidad escrita.
         */
        if (! in_array($request->method(), self::WRITE_METHODS, true)) {
            return $response;
        }

        if (in_array($request->path(), self::EXCLUDED_PATHS, true)) {
            return $response;
        }

        $user = $request->user();

        Log::channel('audit')->info('api', [
            'user_id' => $user?->user_id,
            'rol' => $user?->rol,
            'method' => $request->method(),
            'path' => $request->path(),
            'status' => $response->getStatusCode(),
            'ip' => $request->ip(),
        ]);

        return $response;
    }
}
