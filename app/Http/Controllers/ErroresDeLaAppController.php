<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * DONDE LA APP CUENTA QUE SE ROMPIÓ.
 *
 * Lo llama el `ErrorBoundary` cuando una excepción tumba una pantalla. Sin
 * esto, un fallo en el teléfono de un cliente no dejaba ni una huella: la
 * pantalla se quedaba en blanco y acá no se sabía nada. Lo único visible era
 * una desinstalación, sin saber por qué.
 *
 * ES PÚBLICO, y tiene que serlo: la app puede reventar ANTES de iniciar sesión
 * —resolviendo la sesión guardada, precisamente—. A cambio va con un límite de
 * peticiones bajo, porque escribir sin autenticación es superficie de ataque.
 *
 * NUNCA DEVUELVE UN ERROR. Si algo falla al guardar el informe se registra y se
 * responde que sí igual: la app ya está rota, y encadenar un segundo fallo
 * dentro del manejador del primero es exactamente cómo se pierde la
 * información que se venía a buscar.
 */
class ErroresDeLaAppController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        try {
            $datos = $request->validate([
                'mensaje'     => ['required', 'string', 'max:500'],
                'traza'       => ['nullable', 'string'],
                'pantalla'    => ['nullable', 'string', 'max:120'],
                'plataforma'  => ['nullable', 'string', 'max:10'],
                'app_version' => ['nullable', 'string', 'max:20'],
            ]);

            DB::table('client_errors')->insert([
                'user_id'     => $request->user()?->user_id,
                'platform'    => $datos['plataforma'] ?? null,
                'app_version' => $datos['app_version'] ?? null,
                'pantalla'    => $datos['pantalla'] ?? null,
                /*
                 * Corte EXACTO, sin los puntos suspensivos que añade
                 * `Str::limit` por defecto: la columna tiene un tope duro y un
                 * marcador de tres caracteres es justo lo que la desborda. Se
                 * descubrió con la prueba de abajo, que esperaba 4000 y
                 * recibía 4003.
                 */
                'mensaje'     => Str::limit($datos['mensaje'], 500, ''),
                /*
                 * La traza se recorta: en React Native trae referencias al
                 * paquete entero y son cientos de kilobytes. Las primeras
                 * líneas dicen dónde fue; el resto es ruido de la librería.
                 */
                'traza'       => Str::limit((string) ($datos['traza'] ?? ''), 4000, ''),
                'created_at'  => now(),
            ]);
        } catch (\Throwable $e) {
            Log::warning('No se pudo guardar un fallo de la app', [
                'error' => $e->getMessage(),
            ]);
        }

        return response()->json(['ok' => true]);
    }
}
