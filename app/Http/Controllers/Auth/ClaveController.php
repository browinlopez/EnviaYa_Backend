<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\ConfirmarNuevaContrasenaRequest;
use App\Http\Requests\Auth\PedirCodigoDeRecuperacionRequest;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;

class ClaveController extends Controller
{
    // Restablecer contraseña (envía link al correo)
    public function resetPassword(PedirCodigoDeRecuperacionRequest $request)
    {
        $status = Password::sendResetLink(
            $request->only('email')
        );

        return $status === Password::RESET_LINK_SENT
            ? response()->json(['message' => 'Se envió el enlace al correo'])
            : response()->json(['message' => 'No se pudo enviar el enlace'], 500);
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
