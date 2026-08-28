<?php

namespace App\Http\Middleware;

use App\Models\Area;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Puerta de las CATEGORÍAS, que son dos cosas detrás de una sola ruta.
 *
 * `/admin/categories` sirve dos catálogos según el `scope` que se le mande:
 * las categorías de producto y las de negocio. En el catálogo de módulos son
 * dos claves distintas —`categorias` y `categorias-negocio`—, cada una con su
 * casilla en la matriz de Áreas y su entrada en el menú.
 *
 * El servidor, en cambio, exigía `categorias` para las cuatro rutas. Con eso:
 *
 *  · quien tuviera `categorias` y NO `categorias-negocio` no veía la sección en
 *    el menú, pero podía crear y borrar categorías de negocio llamando la ruta
 *    a mano;
 *  · quien tuviera `categorias-negocio` y NO `categorias` veía la entrada del
 *    menú y recibía un 403 al abrirla — lo que el proyecto trata como un fallo
 *    de diseño, no como un acierto de la seguridad.
 *
 * Hoy las áreas sembradas llevan las dos claves juntas, así que no se nota. Se
 * nota el día que alguien las separe en **Control → Áreas**, que es
 * exactamente para lo que existe esa pantalla.
 *
 * `modulo:` no sirve acá por el mismo motivo que no sirve para los archivos: la
 * clave no se sabe hasta leer la petición.
 */
class EnsureCategoryAccess
{
    private const MODULO = [
        'product'  => 'categorias',
        'business' => 'categorias-negocio',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $scope  = (string) $request->input('scope', 'product');
        $modulo = self::MODULO[$scope] ?? null;

        if ($modulo === null) {
            return response()->json([
                'message' => "No existe un catálogo de categorías \"{$scope}\".",
            ], 422);
        }

        $user = $request->user();
        $area = $user?->area_id ? Area::find($user->area_id) : null;

        if (!$area || (int) $area->state !== 1) {
            return response()->json([
                'message' => 'Tu cuenta no tiene un área asignada. Pídele a Tecnología que te asigne una.',
            ], 403);
        }

        $nivel = $user->access_level ?? Area::NIVEL_GESTOR;

        // Solo GET consulta; crear, editar y borrar tocan el catálogo.
        $escribe = $request->method() !== 'GET';

        $permitido = $escribe
            ? $area->puedeGestionar($modulo, $nivel)
            : $area->puedeVer($modulo, $nivel);

        if (!$permitido) {
            $que = $scope === 'business' ? 'de negocio' : 'de producto';

            return response()->json([
                'message' => $escribe && $area->puedeVer($modulo, $nivel)
                    ? "Puedes consultar las categorías {$que}, no modificarlas."
                    : "Tu área ({$area->name}) no tiene acceso a las categorías {$que}.",
            ], 403);
        }

        return $next($request);
    }
}
