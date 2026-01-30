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
            $result = DB::transaction(function () use ($validated) {
                // Crear usuario
                $user = User::create([
                    'name'     => $validated['name'],
                    'email'    => $validated['email'],
                    'password' => Hash::make($validated['password']),
                    'phone'    => $validated['phone'],
                    'rol'      => 1,
                    'state'    => true,
                ]);

                // Crear perfil de buyer
                $buyer = Buyer::create([
                    'user_id' => $user->user_id,
                    'qualification' => 0.00,
                    'state' => true,
                ]);

                // Si pertenece a conjunto y se envió complex_id
                if ($validated['belongs_to_complex'] && !empty($validated['complex_id'])) {
                    BuyerComplex::create([
                        'buyer_id'   => $buyer->buyer_id,
                        'complex_id' => $validated['complex_id'],
                    ]);
                }

                // Crear token
                $token = $user->createToken('auth_token')->plainTextToken;

                return [
                    'user'  => $user,
                    'buyer' => $buyer,
                    'token' => $token,
                ];
            });

            return response()->json($result, 201);
        } catch (\Illuminate\Database\QueryException $e) {
            // Capturamos error de correo duplicado
            if ($e->errorInfo[1] == 1062) { // 1062 es código MySQL para duplicado
                return response()->json([
                    'message' => 'El correo ya está registrado'
                ], 409);
            }

            return response()->json([
                'message' => 'Error al registrar el usuario',
                'error' => $e->getMessage()
            ], 500);
        } catch (\Exception $e) {
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

        $token = $user->createToken('auth_token')->plainTextToken;

        // Cargar la relación según el rol
        if ($user->rol == 1) {
            // Buyer
            $user->load('buyer');
        } elseif ($user->rol == 2) {
            // Owner + sus negocios
            $user->load(['owner.businesses']);
        } elseif ($user->rol == 3) {
            // Domiciliario + negocios (si aplica)
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
