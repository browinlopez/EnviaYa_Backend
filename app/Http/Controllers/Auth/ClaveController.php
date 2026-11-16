<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\ConfirmarNuevaContrasenaRequest;
use App\Http\Requests\Auth\PedirCodigoDeRecuperacionRequest;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Password;
use Throwable;

class ClaveController extends Controller
{
    /**
     * PEDIR EL ENLACE PARA RESTABLECER LA CONTRASEÑA.
     *
     * LA MISMA RESPUESTA SIEMPRE, Y ES EL ARREGLO.
     *
     * Antes esto devolvía 200 cuando el correo existía y **500 cuando no**:
     *
     *     return $status === Password::RESET_LINK_SENT
     *         ? response()->json(['message' => 'Se envió el enlace al correo'])
     *         : response()->json(['message' => 'No se pudo enviar el enlace'], 500);
     *
     * Con eso, cualquiera averigua si una cuenta está registrada probando
     * correos y mirando el código: 200 es sí, 500 es no. Es la fuga de
     * enumeración de cuentas que §35 nombra, y en esta plataforma no es
     * abstracta —saber que un teléfono o un correo tiene cuenta en una app de
     * domicilios dice dónde compra alguien—.
     *
     * Y tenía una segunda cara peor para quien no hace nada malo:
     * `RESET_THROTTLED` caía en la misma rama, así que el usuario legítimo que
     * pulsaba dos veces el botón recibía «No se pudo enviar el enlace» con un
     * 500, cuando lo único que pasaba era que ya se le había enviado.
     *
     * Ahora los tres casos que NO son un fallo del servidor —enviado, correo
     * desconocido y pedido demasiado pronto— contestan lo mismo: 200 y un
     * texto que no afirma que la cuenta exista. Quien tiene cuenta recibe el
     * correo; quien no, no se entera de nada. El 500 queda para lo que de
     * verdad es un fallo nuestro: que el envío reviente.
     *
     * CAMBIA LO QUE VE LA APP, y conviene saberlo: donde antes salía un error
     * al escribir un correo equivocado, ahora sale el mismo mensaje de
     * siempre. Es a propósito —es justo lo que cierra la fuga— y es el
     * comportamiento que trae Laravel de fábrica en Fortify.
     */
    public function resetPassword(PedirCodigoDeRecuperacionRequest $request)
    {
        /*
         * El texto no promete nada: «si esa cuenta existe». Prometer el envío
         * sería mentir a quien escribió mal su correo, y es la frase que
         * permite no distinguir los casos.
         */
        $mismaRespuesta = response()->json([
            'message' => 'Si esa cuenta existe, le enviamos un enlace para '
                .'cambiar la contraseña. Revisa tu correo.',
        ]);

        try {
            $estado = Password::sendResetLink($request->only('email'));
        } catch (Throwable $e) {
            /*
             * Aquí sí es culpa nuestra: el correo no salió. Se registra con el
             * motivo y se contesta 500, porque reintentar tiene sentido.
             */
            Log::error('No se pudo enviar el enlace de restablecimiento', [
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'message' => 'No pudimos enviar el correo en este momento. '
                    .'Intenta de nuevo en un momento.',
            ], 500);
        }

        if ($estado === Password::RESET_LINK_SENT) {
            return $mismaRespuesta;
        }

        /*
         * Correo que no está, o pedido antes de que pase el minuto de espera.
         * Ninguno de los dos es un fallo, y los dos tienen que ser
         * indistinguibles del envío. Queda en el registro para poder
         * diagnosticar, que es donde esta información sí puede estar.
         */
        if (in_array($estado, [Password::INVALID_USER, Password::RESET_THROTTLED], true)) {
            Log::info('Enlace de restablecimiento no enviado', ['motivo' => $estado]);

            return $mismaRespuesta;
        }

        Log::error('Estado inesperado al pedir el enlace', ['estado' => $estado]);

        return response()->json([
            'message' => 'No pudimos enviar el correo en este momento. '
                .'Intenta de nuevo en un momento.',
        ], 500);
    }

    // Confirmar restablecimiento de contraseña (token + nueva contraseña)
    public function resetPasswordConfirm(ConfirmarNuevaContrasenaRequest $request)
    {

        $status = Password::reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            function (User $user) use ($request) {
                $user->forceFill([
                    'password' => Hash::make($request->password),
                ])->save();
            }
        );

        return $status === Password::PASSWORD_RESET
            ? response()->json(['message' => 'Contraseña actualizada correctamente'])
            : response()->json(['message' => __($status)], 400);
    }
}
