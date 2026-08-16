<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * El servidor embebido de PHP (php artisan serve => php -S) responde sin
 * Content-Length. Sin esa cabecera el cliente solo sabe dónde termina el
 * cuerpo cuando se cierra el socket, y sobre una red real (la app en WiFi)
 * esa carrera se pierde: llegan respuestas cortadas por 1-2 bytes y el JSON
 * queda inválido. Fijar Content-Length explícitamente elimina la ambigüedad.
 *
 * Solo actúa bajo cli-server; en producción (nginx/apache) la cabecera ya
 * viene del servidor web y este middleware no interviene.
 */
class SetContentLengthForDevServer
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if (PHP_SAPI !== 'cli-server') {
            return $response;
        }

        // Las respuestas en streaming no tienen un cuerpo medible de antemano.
        if ($response instanceof StreamedResponse || $response instanceof BinaryFileResponse) {
            return $response;
        }

        $content = $response->getContent();

        if ($content !== false) {
            $response->headers->set('Content-Length', (string) strlen($content));
        }

        return $response;
    }

    /**
     * terminate() corre DESPUÉS de que la respuesta ya se escribió en el socket
     * y justo antes de que el script termine, que es cuando php -S lo cierra.
     *
     * Ese cierre es abrupto: sobre el NAT del emulador de Android descarta los
     * últimos bytes que aún viajaban. Medido: una respuesta de 38.044 bytes
     * llegaba con 38.039 (5 de menos), y el cliente la rechazaba con
     * ERR_NETWORK por no cuadrar con Content-Length. Una pausa corta antes de
     * cerrar da tiempo a que el socket drene.
     *
     * Solo bajo cli-server; en producción no se ejecuta.
     */
    public function terminate(Request $request, Response $response): void
    {
        if (PHP_SAPI === 'cli-server') {
            usleep(50000); // 50 ms
        }
    }
}
