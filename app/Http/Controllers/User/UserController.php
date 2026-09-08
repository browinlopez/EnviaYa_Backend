<?php

namespace App\Http\Controllers\User;

use App\Http\Controllers\Concerns\ComprobarPertenencia;
use App\Http\Controllers\Controller;
use App\Models\Buyer\Buyer;
use App\Models\User;
use App\Models\User\UserAddress;
use App\Models\Notification;
use App\Services\MunicipioPorNombre;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;

class UserController extends Controller
{
    use ComprobarPertenencia;

    // Listar todos los usuarios con relaciones
    public function index()
    {
        $users = User::with([
            'buyer.complexes.residentialComplex',
            'addresses'
        ])
            ->where('rol', 4)
            ->get();

        $formatted = $users->map(function ($user) {
            return [
                'user_id'       => $user->user_id,
                'name'          => $user->name,
                'email'         => $user->email,
                'phone'         => $user->phone,
                'address'       => $user->address,
                'rol'           => $user->rol,
                'qualification' => $user->buyer ? $user->buyer->qualification : null,
                'state'         => $user->state,

                // Buyer directo (sin arreglo)
                'buyer_id' => $user->buyer ? $user->buyer->buyer_id : null,

                // Complexes simplificado (id y nombre del residencial)
                'complexes' => $user->buyer && $user->buyer->complexes ? $user->buyer->complexes->map(function ($complex) {
                    return [
                        'id'   => $complex->id,
                        'name' => $complex->residentialComplex ? $complex->residentialComplex->name : null,
                    ];
                }) : [],

                // Addresses completo
                'addresses' => $user->addresses,
            ];
        });

        return response()->json($formatted);
    }

    // Detalle de un usuario
    public function show(Request $request)
    {
        $request->validate([
            'user_id' => 'required|integer|exists:user,user_id',
        ]);

        $user = User::with([
            'buyer.complexes.residentialComplex',
            'addresses'
        ])
            ->where('rol', 4)
            ->findOrFail($request->user_id);

        $formatted = [
            'user_id'       => $user->user_id,
            'name'          => $user->name,
            'email'         => $user->email,
            'phone'         => $user->phone,
            'address'       => $user->address,
            'rol'           => $user->rol,
            'qualification' => $user->buyer ? $user->buyer->qualification : null,
            'state'         => $user->state,

            // Buyer directo (sin arreglo)
            'buyer_id' => $user->buyer ? $user->buyer->buyer_id : null,

            // Complexes simplificado (id y nombre del residencial)
            'complexes' => $user->buyer && $user->buyer->complexes ? $user->buyer->complexes->map(function ($complex) {
                return [
                    'id'   => $complex->id,
                    'name' => $complex->residentialComplex ? $complex->residentialComplex->name : null,
                ];
            }) : [],

            // Addresses completo
            'addresses' => $user->addresses,
        ];

        return response()->json($formatted);
    }

    // Actualizar usuario
    /**
     * Actualiza el perfil del usuario autenticado.
     *
     * Antes tomaba el user_id del cuerpo y actualizaba a cualquiera: con una
     * sesión válida se podían editar los datos de otra persona. Además dejaba
     * cambiar `rol`, lo que permitía convertirse en tendero o administrador.
     * Ahora solo se edita el propio perfil y solo los campos de contacto; el
     * rol, la calificación y el estado se gestionan por sus propios flujos.
     */
    public function update(Request $request)
    {
        $user = $request->user();

        $request->validate([
            'name'    => 'nullable|string|max:255',
            'email'   => 'nullable|email|unique:user,email,' . $user->user_id . ',user_id',
            'phone'   => 'nullable|string|max:20',
            'address' => 'nullable|string|max:225',
        ]);

        $user->update($request->only(['name', 'email', 'phone', 'address']));

        return response()->json($user);
    }

    /**
     * Cambia la contraseña del usuario autenticado.
     *
     * Se exige la contraseña actual: sin eso, quien tuviera el teléfono
     * desbloqueado un momento podría dejar al dueño fuera de su cuenta.
     */
    public function updatePassword(Request $request)
    {
        $request->validate([
            'current_password' => 'required|string',
            'password'         => ['required', 'confirmed', Password::min(8)],
        ]);

        $user = $request->user();

        if (!Hash::check($request->current_password, $user->password)) {
            return response()->json([
                'message' => 'La contraseña actual no es correcta',
                'errors'  => [
                    'current_password' => ['La contraseña actual no es correcta'],
                ],
            ], 422);
        }

        if (Hash::check($request->password, $user->password)) {
            return response()->json([
                'message' => 'La nueva contraseña debe ser distinta a la actual',
                'errors'  => [
                    'password' => ['La nueva contraseña debe ser distinta a la actual'],
                ],
            ], 422);
        }

        $user->password = Hash::make($request->password);
        $user->save();

        // Se cierran las demás sesiones: si la contraseña se cambió porque
        // alguien más tenía acceso, ese acceso debe terminar aquí.
        $actual = $request->user()->currentAccessToken();
        $user->tokens()->where('id', '!=', $actual?->id)->delete();

        return response()->json([
            'message' => 'Contraseña actualizada correctamente',
        ]);
    }

    // Eliminar usuario
    public function desactivate(Request $request)
    {
        $request->validate([
            'email' => 'required|email|exists:user,email',
        ]);

        $user = User::where('email', $request->email)->firstOrFail();

        /*
         * BAJA DE LA PROPIA CUENTA, no de la de otro.
         *
         * Bastaba con saber un correo —y el de cualquier tienda esta
         * publicado— para dejar a esa persona fuera. Uno por uno se podia
         * apagar la plataforma entera, administradores incluidos.
         */
        if ($no = $this->negarCuentaAjena($request, $user->user_id)) {
            return $no;
        }

        if ($user->state === true) {
            $user->state = false;
            $user->save();

            return response()->json(['message' => 'Usuario desactivado correctamente']);
        }

        return response()->json(['message' => 'El usuario ya está desactivado'], 200);
    }

    // Obtener perfil buyer del usuario
    public function getBuyerProfile(Request $request)
    {
        $request->validate([
            'user_id' => 'required|integer|exists:user,user_id',
        ]);

        $buyer = Buyer::with([
            'user:user_id,name,email,phone,address,rol,qualification,state'
        ])->where('user_id', $request->user_id)->first();

        if (!$buyer) {
            return response()->json(['message' => 'El usuario no tiene perfil comprador'], 404);
        }

        $response = [
            'buyer_id' => $buyer->buyer_id,
            'user_id' => $buyer->user_id,
            'qualification' => $buyer->qualification,
            'state' => $buyer->state,
            'user' => [
                'user_id' => $buyer->user->user_id,
                'name' => $buyer->user->name,
                'email' => $buyer->user->email,
                'phone' => $buyer->user->phone,
                'address' => $buyer->user->address,
                'rol' => $buyer->user->rol,
                'qualification' => $buyer->user->qualification,
                'state' => $buyer->user->state
            ]
        ];

        return response()->json($response);
    }


    // ----------------------------
    // Notificaciones del usuario
    // ----------------------------
}
