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
 * rechazados, validaciones fallidas, etc.). Los GET no se registran para
 * no generar ruido, salvo los de WATCHED_READS, y las rutas de alto
 * volumen se excluyen explícitamente.
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

    /**
     * LAS NUEVE LECTURAS QUE SE ESTÁN MIDIENDO, Y POR QUÉ ESTÁN AQUÍ.
     *
     * `Documentacion/SUPERFICIE-DE-API-SIN-USO.md` lista 31 rutas que ningún
     * cliente de este repositorio llama. 22 son escrituras y el registro ya
     * las ve. Estas nueve son GET: el filtro de verbo las saltaba, así que
     * sobre ellas el registro no decía nada y la decisión de retirarlas no
     * tenía con qué sostenerse.
     *
     * No hacen falta cambios en `routes/api.php`: las nueve ya están dentro
     * del grupo `['auth:sanctum', 'audit.api']`, solo las descartaba el
     * verbo.
     *
     * SON TEMPORALES. Están aquí mientras se decide, y se van con la
     * decisión: o la ruta se retira (y la línea sobra), o resulta que
     * alguien la llama (y entonces se queda la ruta y sobra la línea igual).
     * Si alguna resulta ser de alto volumen se nota en un día y se pasa a
     * EXCLUDED_PATHS, que es justo para eso.
     */
    private const WATCHED_READS = [
        'v1/reviews/business/all',
        'v1/reviews/domiciliaries/all',
        'v1/reviews/users/all',
        'v1/reviews/user/by',
        'v1/categories/show',
        'v1/domiciliaries/listDomiciliary',
        'v1/businesses/affiliations/usersBusinesses',
        'v1/orders/pending-review',
        'v1/paymentForms',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $esEscritura = in_array($request->method(), self::WRITE_METHODS, true);
        $esLecturaVigilada = $request->method() === 'GET'
            && in_array($request->path(), self::WATCHED_READS, true);

        if (! $esEscritura && ! $esLecturaVigilada) {
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

    /** Las lecturas que se están midiendo, para que el informe las lea de aquí. */
    public static function lecturasVigiladas(): array
    {
        return self::WATCHED_READS;
    }
}
