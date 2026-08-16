<?php

namespace App\Http\Middleware;

use App\Models\Area;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Puerta por MÓDULO.
 *
 * `EnsureAdmin` responde "¿esta persona entra al panel?". Este responde la
 * pregunta siguiente: "¿le toca esta sección, y puede tocarla o solo mirarla?".
 * Son dos preguntas distintas y por eso son dos middlewares: el primero se
 * aplica a todo el bloque y este solo donde hace falta.
 *
 * Se usa así en las rutas:
 *
 *     ->middleware('modulo:pagos')          — basta con poder verlo
 *     ->middleware('modulo:pagos,gestionar') — hace falta poder editarlo
 *
 * El panel oculta lo que no corresponde, pero eso es comodidad de la interfaz:
 * la lista de rutas es pública y cualquiera con un token puede llamarlas a
 * mano. La autorización real es esta.
 */
class EnsureModuleAccess
{
    public function handle(Request $request, Closure $next, string $modulo, string $alcance = 'ver'): Response
    {
        $user = $request->user();

        if (!$user) {
            return response()->json(['message' => 'No autenticado.'], 401);
        }

        $area = $user->area_id ? Area::find($user->area_id) : null;

        if (!$area || (int) $area->state !== 1) {
            return response()->json([
                'message' => 'Tu cuenta no tiene un área asignada. Pídele a Tecnología que te asigne una.',
            ], 403);
        }

        $permitido = $alcance === 'gestionar'
            ? $area->puedeGestionar($modulo)
            : $area->puedeVer($modulo);

        if (!$permitido) {
            return response()->json([
                'message' => $alcance === 'gestionar'
                    ? "Tu área ({$area->name}) puede consultar esta sección, pero no modificarla."
                    : "Tu área ({$area->name}) no tiene acceso a esta sección.",
            ], 403);
        }

        return $next($request);
    }
}
