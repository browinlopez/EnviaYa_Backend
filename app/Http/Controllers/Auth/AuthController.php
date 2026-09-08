<?php

namespace App\Http\Controllers\Auth;

use App\Services\Totp;
use App\Http\Controllers\Controller;
use App\Models\Buyer\Buyer;
use App\Models\Buyer\BuyerComplex;
use App\Models\Buyer\ResidentialComplex;
use App\Models\User\UserAddress;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Auth\Events\Registered;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use App\Mail\VerifyEmailCustomMail;

class AuthController extends Controller
{
    // Login
    public function login(Request $request)
    {
        $credentials = $request->validate([
            'email'    => 'required|email',
            'password' => 'required|string',
        ]);

        $user = User::where('email', $credentials['email'])->first();

        if (!$user || !Hash::check($credentials['password'], $user->password)) {
            return response()->json(['message' => 'Credenciales incorrectas'], 401);
        }

        /*
         * Cuenta deshabilitada por la administración.
         *
         * Hasta ahora `user.state` no se miraba en ningún lado: el panel
         * permitía marcar a alguien como inactivo y la persona seguía
         * entrando a la app con normalidad. Bloquear tiene que impedir el
         * acceso o no es un bloqueo.
         *
         * Se comprueba ANTES que el correo verificado porque es la razón más
         * grave, y así el mensaje que recibe es el correcto.
         */
        if (!$user->state) {
            return response()->json([
                'message' => 'Tu cuenta está deshabilitada. Comunícate con el administrador.',
                'reason'  => 'account_disabled',
            ], 403);
        }

        // ⛔ BLOQUEAR SI NO VERIFICÓ CORREO
        if (!$user->hasVerifiedEmail()) {
            return response()->json([
                'message' => 'Debes verificar tu correo antes de iniciar sesión'
            ], 403);
        }

        /*
         * SEGUNDO FACTOR.
         *
         * Va DESPUÉS de comprobar la contraseña, no antes: pedir el código a
         * quien no acertó la clave confirmaría que esa cuenta existe y que tiene
         * segundo factor, que es información que no hay por qué regalar.
         *
         * Si falta el código se responde 401 con `two_factor_required`, y el
         * cliente vuelve a llamar con `two_factor_code`. Un código de
         * recuperación también sirve, y se gasta al usarlo.
         */
        if ($user->tieneSegundoFactor()) {
            $codigo = trim((string) $request->input('two_factor_code', ''));

            if ($codigo === '') {
                return response()->json([
                    'message' => 'Escribe el código de tu aplicación de autenticación.',
                    'reason'  => 'two_factor_required',
                ], 401);
            }

            if (!$this->segundoFactorValido($user, $codigo)) {
                return response()->json([
                    'message' => 'El código no es válido o ya se usó.',
                    'reason'  => 'two_factor_invalid',
                ], 401);
            }
        }

        $token = $user->createToken('auth_token')->plainTextToken;

        /*
         * De dónde salió esta sesión.
         *
         * Se guarda para que la lista de sesiones abiertas sirva de algo: sin
         * esto dice "token #3, token #7" y nadie puede reconocer la que no es
         * suya, que es lo único para lo que existe esa lista.
         */
        $user->tokens()->latest('id')->limit(1)->update([
            'ip'         => $request->ip(),
            'user_agent' => substr((string) $request->userAgent(), 0, 255),
        ]);

        if ($user->rol == 1) {
            $user->load('buyer');
        } elseif ($user->rol == 2) {
            $user->load(['owner.businesses']);
        } elseif ($user->rol == 3) {
            $user->load(['domiciliary.businesses']);
        }

        return response()->json([
            'user'  => $user,
            'token' => $token,
        ]);
    }

    /**
     * ¿Es válido el código? Vale el del teléfono o uno de recuperación.
     *
     * El de recuperación SE GASTA: sirve una vez y se borra de la lista. Un
     * código de recuperación reutilizable es una segunda contraseña permanente
     * escrita en un papel.
     */
    private function segundoFactorValido($user, string $codigo): bool
    {
        if (Totp::verificar($user->two_factor_secret, $codigo)) {
            return true;
        }

        $codigos = $user->two_factor_recovery_codes ?? [];

        foreach ($codigos as $i => $resumen) {
            if (Hash::check($codigo, $resumen)) {
                unset($codigos[$i]);

                $user->forceFill([
                    'two_factor_recovery_codes' => array_values($codigos),
                ])->save();

                return true;
            }
        }

        return false;
    }

    // Logout
    public function logout(Request $request)
    {
        /*
         * Se cierran las SESIONES, no el acceso con huella.
         *
         * Antes esto borraba todos los tokens, y con ellos el que el teléfono
         * guarda tras la huella: cerrar sesión obligaba a escribir la
         * contraseña otra vez, que es justo lo que la huella viene a evitar.
         *
         * El token biométrico solo sirve para pedir una sesión nueva —está
         * limitado a esa habilidad— y se borra desde «olvidar este teléfono»
         * o desde la pantalla de seguridad. Salir no es lo mismo que querer
         * que el teléfono olvide la cuenta.
         */
        $request->user()
            ->tokens()
            ->where('name', '!=', AccesoBiometricoController::NOMBRE_TOKEN)
            ->delete();

        return response()->json(['message' => 'Sesión cerrada correctamente']);
    }

    // Perfil
    public function profile(Request $request)
    {
        return response()->json($request->user());
    }
}
