<?php

namespace App\Http\Controllers\Auth;

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
            return response()->json(['message' => 'Token inválido'], 400);
        }

        if ($user->email_verified_at) {
            return response()->json(['message' => 'El correo ya fue verificado']);
        }

        if ($user->email_verification_expires_at < Carbon::now()) {
            return response()->json([
                'message' => 'El enlace de verificación ha expirado'
            ], 410);
        }

        $user->update([
            'email_verified_at' => Carbon::now(),
            'email_verification_token' => null,
            'email_verification_expires_at' => null,
        ]);

        return response()->json([
            'message' => 'Correo verificado correctamente'
        ]);
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
            'email' => 'required|email'
        ]);

        $user = User::where('email', $request->email)->first();

        // 🔒 Mensaje genérico (seguridad)
        if (!$user) {
            return response()->json([
                'message' => 'Si el correo existe, se enviará un enlace de verificación.'
            ]);
        }

        if ($user->email_verified_at) {
            return response()->json([
                'message' => 'El correo ya está verificado.'
            ]);
        }

        // ⏳ Si no tiene token o expiró, regenerar
        if (
            !$user->email_verification_token ||
            !$user->email_verification_expires_at ||
            $user->email_verification_expires_at < Carbon::now()
        ) {
            $user->update([
                'email_verification_token' => Str::random(60),
                'email_verification_expires_at' => Carbon::now()->addMinutes(60),
            ]);
        }

        // 📩 Reenviar correo
        $this->sendVerificationEmail($user);

        return response()->json([
            'message' => 'Si el correo existe, se ha enviado el enlace de verificación.'
        ]);
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

        // ⛔ BLOQUEAR SI NO VERIFICÓ CORREO
        if (!$user->hasVerifiedEmail()) {
            return response()->json([
                'message' => 'Debes verificar tu correo antes de iniciar sesión'
            ], 403);
        }

        $token = $user->createToken('auth_token')->plainTextToken;

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
}
