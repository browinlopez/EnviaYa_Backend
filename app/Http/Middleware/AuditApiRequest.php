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
 * no generar ruido, y las rutas de alto volumen se excluyen explícitamente.
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

        if (!in_array($request->method(), self::WRITE_METHODS, true)) {
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
