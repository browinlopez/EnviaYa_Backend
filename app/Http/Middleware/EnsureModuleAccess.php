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

        // El nivel recorta lo que el área concede: un auxiliar ve lo mismo que
        // su jefe y no modifica nada.
        $nivel = $user->access_level ?? Area::NIVEL_GESTOR;

        $permitido = $alcance === 'gestionar'
            ? $area->puedeGestionar($modulo, $nivel)
            : $area->puedeVer($modulo, $nivel);

        if (!$permitido) {
            return response()->json([
                'message' => $this->explicar($area, $modulo, $alcance, $nivel),
            ], 403);
        }

        return $next($request);
    }

    /**
     * Por qué se rechazó, en términos que la persona pueda accionar.
     *
     * Se distinguen tres casos porque llevan a tres conversaciones distintas:
     * pedir el módulo, pedir que le suban el nivel, o entender que esa sección
     * no es de su área. Un "403" genérico las confunde todas y termina en un
     * mensaje a Tecnología que no dice qué hace falta.
     */
    private function explicar(Area $area, string $modulo, string $alcance, string $nivel): string
    {
        if ($alcance !== 'gestionar') {
            return "Tu área ({$area->name}) no tiene acceso a esta sección.";
        }

        // El área sí lo gestiona; lo que falta es el nivel de la persona.
        if ($nivel === Area::NIVEL_CONSULTA && $area->puedeGestionar($modulo)) {
            return 'Tu acceso es de solo consulta. Para modificar esta sección '
                . 'pídele a Tecnología que te cambie el nivel a gestor.';
        }

        return "Tu área ({$area->name}) puede consultar esta sección, pero no modificarla.";
    }
}
