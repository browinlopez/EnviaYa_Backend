<?php

namespace App\Http\Middleware;

use App\Models\Area;
use App\Services\MediaService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Puerta de los ARCHIVOS.
 *
 * `modulo:` no sirve acá porque el módulo depende de la URL: los archivos de un
 * negocio los manda `negocios` y los de un banner, `marketing.banners`. Con una
 * sola clave fija habría que elegir una y dejar las demás mal.
 *
 * Eran las únicas rutas de `/admin` sin puerta por módulo. Bastaba con ser del
 * equipo para subir o borrar archivos de cualquier cosa: un auxiliar de SST en
 * solo consulta podía borrar el logo de un negocio, y todo el reparto por áreas
 * se saltaba por ahí. Que la pantalla no ofreciera el botón no es una defensa —
 * la ruta se puede llamar a mano.
 *
 * LEER pide `ver`; subir, borrar y marcar principal piden `gestionar`, que es
 * lo mismo que se exige para editar el registro al que pertenecen.
 */
class EnsureMediaAccess
{
    public function handle(Request $request, Closure $next): Response
    {
        $entidad = (string) $request->route('entidad');
        $modulo  = app(MediaService::class)->moduloDe($entidad);

        if ($modulo === null) {
            return response()->json([
                'message' => "Los registros de tipo \"{$entidad}\" no manejan archivos.",
            ], 404);
        }

        $user = $request->user();
        $area = $user?->area_id ? Area::find($user->area_id) : null;

        if (!$area || (int) $area->state !== 1) {
            return response()->json([
                'message' => 'Tu cuenta no tiene un área asignada. Pídele a Tecnología que te asigne una.',
            ], 403);
        }

        $nivel = $user->access_level ?? Area::NIVEL_GESTOR;

        // Solo GET consulta; todo lo demás toca los archivos.
        $escribe = $request->method() !== 'GET';

        $permitido = $escribe
            ? $area->puedeGestionar($modulo, $nivel)
            : $area->puedeVer($modulo, $nivel);

        if (!$permitido) {
            return response()->json([
                'message' => $escribe
                    ? ($area->puedeVer($modulo, $nivel)
                        ? 'Puedes consultar estos archivos, no modificarlos.'
                        : 'Los archivos de esta sección no son de tu área.')
                    : 'Los archivos de esta sección no son de tu área.',
            ], 403);
        }

        return $next($request);
    }
}
