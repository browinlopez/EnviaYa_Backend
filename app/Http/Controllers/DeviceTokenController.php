<?php

namespace App\Http\Controllers;

use App\Models\DeviceToken;
use Illuminate\Http\Request;

/**
 * REGISTRO DEL TELÉFONO PARA RECIBIR NOTIFICACIONES
 *
 * La app llama a `POST /v1/devices` al iniciar sesión y cada vez que el
 * proveedor le rota el token —FCM lo hace solo—. Es idempotente: el mismo token
 * actualiza su fila en vez de crear otra.
 *
 * EL TOKEN CAMBIA DE DUEÑO. Si alguien cierra sesión en un teléfono y entra otra
 * persona, el token es el mismo y ahora es de ella. Por eso la clave única es el
 * token y el registro REASIGNA el `user_id`: si se conservara la fila anterior,
 * la promoción para compradores de Soledad le llegaría al dueño anterior del
 * teléfono.
 */
class DeviceTokenController extends Controller
{
    public function store(Request $request)
    {
        $datos = $request->validate([
            'token'       => 'required|string|max:255',
            'platform'    => 'required|in:android,ios,web',
            'app_version' => 'nullable|string|max:20',
            'device_name' => 'nullable|string|max:80',
        ]);

        DeviceToken::updateOrCreate(
            ['token' => $datos['token']],
            [
                'user_id'      => $request->user()->user_id,
                'platform'     => $datos['platform'],
                'app_version'  => $datos['app_version'] ?? null,
                'device_name'  => $datos['device_name'] ?? null,
                'last_seen_at' => now(),
                /*
                 * Se revive un token que se había marcado como muerto. Pasa
                 * cuando alguien reinstala la app: el proveedor puede devolver el
                 * mismo token y sin esto quedaría descartado para siempre.
                 */
                'failed_at'    => null,
                'fail_reason'  => null,
            ],
        );

        return response()->json(['message' => 'Dispositivo registrado.']);
    }

    /**
     * Al cerrar sesión.
     *
     * Se borra la fila y no se marca: cerrar sesión es una decisión de la
     * persona, no un fallo del token. Dejarla marcada como fallida ensuciaría el
     * registro de tokens muertos, que existe para detectar desinstalaciones.
     */
    public function destroy(Request $request)
    {
        $datos = $request->validate(['token' => 'required|string|max:255']);

        DeviceToken::where('token', $datos['token'])
            ->where('user_id', $request->user()->user_id)
            ->delete();

        return response()->json(['message' => 'Dispositivo retirado.']);
    }
}
