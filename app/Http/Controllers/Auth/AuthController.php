<?php

namespace App\Http\Controllers\Auth;

use App\Services\Totp;
use App\Http\Controllers\Controller;
use App\Models\Buyer\Buyer;
use App\Models\Buyer\BuyerComplex;
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
    // Registro
    public function register(Request $request)
    {
        $validated = $request->validate([
            'name'               => 'required|string|max:255',
            'email'              => 'required|string|email|unique:user,email',
            'password'           => 'required|string|min:6',
            'phone'              => 'nullable|string|max:20',
            'belongs_to_complex' => 'boolean',
            'complex_id'         => 'nullable|integer|exists:residential_complexes,complex_id',
        ]);

        try {
            DB::transaction(function () use ($validated) {

                $user = User::create([
                    'name'     => $validated['name'],
                    'email'    => $validated['email'],
                    'password' => Hash::make($validated['password']),
                    'phone'    => $validated['phone'] ?? null,
                    'rol'      => 1,
                    'state'    => true,
                    'email_verification_token' => Str::random(60),
                    'email_verification_expires_at' => Carbon::now()->addMinutes(60),
                ]);

                $buyer = Buyer::create([
                    'user_id' => $user->user_id,
                    'qualification' => 0.00,
                    'state' => true,
                ]);

                if ($validated['belongs_to_complex'] && !empty($validated['complex_id'])) {
                    BuyerComplex::create([
                        'buyer_id' => $buyer->buyer_id,
                        'complex_id' => $validated['complex_id'],
                    ]);
                }

                $this->sendVerificationEmail($user);
            });

            return response()->json([
                'message' => 'Registro exitoso. Revisa tu correo para verificar tu cuenta.'
            ], 201);
        } catch (\Illuminate\Database\QueryException $e) {
            if ($e->errorInfo[1] == 1062) {
                return response()->json([
                    'message' => 'El correo ya está registrado'
                ], 409);
            }

            return response()->json([
                'message' => 'Error al registrar el usuario',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function verify(Request $request)
    {
        $request->validate([
            'token' => 'required|string'
        ]);

        $user = User::where('email_verification_token', $request->token)->first();

        if (!$user) {
            return view('auth.verify-error', [
                'message' => 'Token inválido'
            ]);
        }

        if ($user->email_verified_at) {
            return view('auth.verify-success');
        }

        if (
            !$user->email_verification_expires_at ||
            $user->email_verification_expires_at->isPast()
        ) {
            return view('auth.verify-error', [
                'message' => 'El enlace de verificación ha expirado'
            ]);
        }

        $user->update([
            'email_verified_at' => Carbon::now(),
            'email_verification_token' => null,
            'email_verification_expires_at' => null,
        ]);

        return view('auth.verify-success');
    }

    private function sendVerificationEmail(User $user)
    {
        $actionUrl = config('app.url') // 👈 BACKEND
            . '/verify-email?token='
            . $user->email_verification_token;

        Mail::to($user->email)->send(
            new VerifyEmailCustomMail($actionUrl)
        );
    }

    public function resendVerificationEmail(Request $request)
    {
        $request->validate([
            'email' => 'required|email',
        ]);

        $user = User::where('email', $request->email)->first();

        // 🔒 Respuesta genérica (evita enumeración de usuarios)
        if (!$user) {
            return response()->json([
                'message' => 'Si el correo existe, se enviará un enlace de verificación.'
            ], 200);
        }

        // ✅ Ya verificado
        if ($user->email_verified_at) {
            return response()->json([
                'message' => 'El correo ya está verificado.'
            ], 200);
        }

        // ⏳ Generar nuevo token si no existe o expiró
        if (
            !$user->email_verification_token ||
            !$user->email_verification_expires_at ||
            $user->email_verification_expires_at->isPast()
        ) {
            $user->update([
                'email_verification_token' => Str::random(60),
                'email_verification_expires_at' => now()->addMinutes(60),
            ]);
        }

        // 📩 Enviar correo
        $this->sendVerificationEmail($user);

        return response()->json([
            'message' => 'Si el correo existe, se ha enviado el enlace de verificación.'
        ], 200);
    }

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
        $request->user()->tokens()->delete();

        return response()->json(['message' => 'Sesión cerrada correctamente']);
    }

    // Perfil
    public function profile(Request $request)
    {
        return response()->json($request->user());
    }

    // Restablecer contraseña (envía link al correo)
    public function resetPassword(Request $request)
    {
        $request->validate(['email' => 'required|email']);

        $status = Password::sendResetLink(
            $request->only('email')
        );

        return $status === Password::RESET_LINK_SENT
            ? response()->json(['message' => 'Se envió el enlace al correo'])
            : response()->json(['message' => 'No se pudo enviar el enlace'], 500);
    }

    // Confirmar restablecimiento de contraseña (token + nueva contraseña)
    public function resetPasswordConfirm(Request $request)
    {
        $request->validate([
            'token' => 'required|string',
            'email' => 'required|email',
            'password' => 'required|string|min:6|confirmed',
        ]);

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

    public function resendVerificationEmailWeb(Request $request)
    {
        $request->validate(['email' => 'required|email']);

        $user = \App\Models\User::where('email', $request->email)->first();
        if (!$user) {
            return back()->with('status', 'Si el correo existe, se enviará un enlace de verificación.');
        }

        if ($user->email_verified_at) {
            return redirect()->route('login')->with('status', 'Tu correo ya está verificado.');
        }

        // Generar nuevo token
        $user->update([
            'email_verification_token' => \Illuminate\Support\Str::random(60),
            'email_verification_expires_at' => now()->addMinutes(60),
        ]);

        // Enviar correo
        $actionUrl = config('app.url') . '/verify-email?token=' . $user->email_verification_token;
        \Illuminate\Support\Facades\Mail::to($user->email)->send(new \App\Mail\VerifyEmailCustomMail($actionUrl));

        return back()->with('status', 'Se ha enviado un nuevo correo de verificación.');
    }
}
