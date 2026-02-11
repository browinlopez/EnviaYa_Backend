<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\Buyer\Buyer;
use App\Models\Buyer\BuyerComplex;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Auth\Events\Registered;

class AuthController extends Controller
{
    // Registro
    public function register(Request $request)
    {
        $validated = $request->validate([
            'name'               => 'required|string|max:255',
            'email'              => 'required|string|email|unique:user,email',
            'password'           => 'required|string|min:6',
            'phone'              => 'string|max:20',
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
                ]);

                $buyer = Buyer::create([
                    'user_id' => $user->user_id,
                    'qualification' => 0.00,
                    'state' => true,
                ]);

                if ($validated['belongs_to_complex'] && !empty($validated['complex_id'])) {
                    BuyerComplex::create([
                        'buyer_id'   => $buyer->buyer_id,
                        'complex_id' => $validated['complex_id'],
                    ]);
                }
                  $user->sendEmailVerificationNotification();

                // 🔔 ENVÍA CORREO DE VERIFICACIÓN
                event(new Registered($user));
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
