<?php

namespace App\Http\Controllers\Concerns;

use App\Services\NegocioDelUsuario;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * QUE LO QUE PIDES SEA TUYO.
 *
 * Los permisos del proyecto son por MÓDULO y nunca por REGISTRO:
 * `EnsureModuleAccess` responde «¿puede ver la sección Pedidos?» y jamás
 * «¿puede ver ESTE pedido?». Para el panel del tendero y el del conjunto eso
 * se resolvió con middleware propio —`negocio` y `conjunto` resuelven de quién
 * se trata desde la sesión— pero las rutas antiguas, las que usa la app
 * publicada, siguen recibiendo el identificador en el cuerpo.
 *
 * Y un identificador en el cuerpo es una sugerencia, no una credencial: son
 * correlativos, así que probar el 1, el 2 y el 3 no requiere ingenio.
 *
 * Se comprobó ejecutando: con la cuenta de un COMPRADOR se leyeron 21 pedidos
 * de un negocio ajeno, cada uno con el nombre, el correo, el teléfono y la
 * dirección de quien lo hizo.
 *
 * ESTO NO REEMPLAZA AL MIDDLEWARE `negocio`, que es mejor porque no deja al
 * cliente ni nombrar el negocio. Es lo que se puede poner sin romper las
 * versiones de la app que ya están en los teléfonos, que mandan `business_id`
 * y seguirán mandándolo durante meses.
 *
 * EL ADMINISTRADOR PASA. No por comodidad: el panel interno administra todos
 * los negocios por definición, y sin esta salida habría que duplicar cada
 * endpoint. Su propia puerta —`EnsureAdmin`, rol 4— ya está comprobada aparte.
 */
trait ComprobarPertenencia
{
    /** ¿El negocio es suyo, o es del equipo? */
    protected function administraElNegocio(Request $request, $businessId): bool
    {
        $user = $request->user();

        if (!$user) {
            return false;
        }

        // Rol 4 es el equipo interno; su alcance lo decide `EnsureAdmin`.
        if ((int) $user->rol === 4) {
            return true;
        }

        return app(NegocioDelUsuario::class)
            ->administra((int) $user->user_id, (int) $businessId);
    }

    /**
     * Corta la petición si el negocio no es suyo.
     *
     * Devuelve `null` cuando puede seguir, para poder escribir:
     *
     *     if ($no = $this->negarNegocioAjeno($request, $id)) { return $no; }
     */
    protected function negarNegocioAjeno(Request $request, $businessId): ?JsonResponse
    {
        if ($this->administraElNegocio($request, $businessId)) {
            return null;
        }

        return response()->json(['message' => 'Ese negocio no es tuyo.'], 403);
    }

    /**
     * Corta la petición si la cuenta no es la suya.
     *
     * 403 y no 404: acá no se filtra nada al confirmar que la cuenta existe
     * —el `exists:` de la validación ya lo hizo—, y decir «no eres tú» es más
     * útil que un «no existe» que manda a buscar un fallo donde no lo hay.
     */
    protected function negarCuentaAjena(Request $request, $userId): ?JsonResponse
    {
        $user = $request->user();

        if ($user && ((int) $user->user_id === (int) $userId || (int) $user->rol === 4)) {
            return null;
        }

        return response()->json(['message' => 'Esa cuenta no es tuya.'], 403);
    }
}
