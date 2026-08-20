<?php

namespace App\Http\Controllers\User;

use App\Http\Controllers\Controller;
use App\Models\Buyer\Buyer;
use App\Models\User;
use App\Models\User\UserAddress;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;

class UserController extends Controller
{
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

        if ($user->state === true) {
            $user->state = false;
            $user->save();

            return response()->json(['message' => 'Usuario desactivado correctamente']);
        }

        return response()->json(['message' => 'El usuario ya está desactivado'], 200);
    }

    // Obtener direcciones del usuario con jerarquía completa
    public function getAddresses(Request $request)
    {
        $request->validate([
            'user_id' => 'required|integer|exists:user,user_id',
        ]);

        $addresses = UserAddress::where('user_id', $request->user_id)
            ->where('state', true)
            ->with([
                'alias:alias_id,name',
                'municipality:id,name,department_id',
                'municipality.department:id,name,country_id',
                'municipality.department.country:id,name,iso_code'
            ])
            ->get()
            ->map(function ($address) {
                return [
                    'address_id' => $address->address_id,
                    'address' => $address->address,
                    'latitude' => $address->latitude,
                    'longitude' => $address->longitude,
                    'alias_id' => $address->alias?->alias_id,
                    'alias_name' => $address->alias?->name,
                    'municipality_id' => $address->municipality?->id,
                    'municipality_name' => $address->municipality?->name,
                    'department_id' => $address->municipality?->department?->id,
                    'department_name' => $address->municipality?->department?->name,
                    'country_id' => $address->municipality?->department?->country?->id,
                    'country_name' => $address->municipality?->department?->country?->name,
                    'country_iso_code' => $address->municipality?->department?->country?->iso_code,
                ];
            });

        return response()->json($addresses);
    }

    // Agregar dirección a un usuario
    public function addAddress(Request $request)
    {
        $request->validate([
            'user_id' => 'required|integer|exists:user,user_id',
            'address' => 'required|string|max:225',
            //'municipality_id' => 'required|integer|exists:municipalities,id',
            'alias_id' => 'nullable|integer|exists:alias,alias_id',
            'latitude' => 'nullable|numeric',
            'longitude' => 'nullable|numeric'
        ]);

        $address = UserAddress::create([
            'user_id' => $request->user_id,
            'address' => $request->address,
            //'municipality_id' => $request->municipality_id,
            'alias_id' => $request->alias_id,
            'latitude' => $request->latitude,
            'longitude' => $request->longitude,
            'state' => true
        ]);

        return response()->json($address, 201);
    }

    /**
     * RETIRAR UNA DIRECCIÓN
     *
     * La app ofrecía el botón de borrar desde hacía tiempo —con su diálogo de
     * confirmación y todo— y al aceptar no ocurría nada, porque este endpoint
     * no existía.
     *
     * Se marca como inactiva en vez de borrarla: los pedidos ya hechos
     * apuntan a ella, y eliminarla de verdad dejaría el histórico sin poder
     * decir a dónde se entregó.
     *
     * La comprobación de pertenencia es lo importante: sin ella, cualquiera
     * con sesión podría retirar la dirección de otra persona probando
     * identificadores.
     */
    public function deleteAddress(Request $request, $id)
    {
        $request->validate([
            'user_id' => 'required|integer|exists:user,user_id',
        ]);

        $address = UserAddress::where('address_id', $id)
            ->where('user_id', $request->user_id)
            ->first();

        if (!$address) {
            return response()->json([
                'message' => 'La dirección no existe o no es tuya.',
            ], 404);
        }

        $address->update(['state' => false]);

        return response()->json(['message' => 'Dirección eliminada.']);
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

    // Listar notificaciones
    /*  public function getNotifications(Request $request)
    {
        $request->validate([
            'user_id' => 'required|integer|exists:user,user_id',
        ]);

        $notifications = Notification::where('user_id', $request->user_id)
                            ->orderBy('date', 'desc')
                            ->get();

        return response()->json($notifications);
    }

    // Crear notificación
    public function createNotification(Request $request)
    {
        $request->validate([
            'user_id' => 'required|integer|exists:user,user_id',
            'message' => 'required|string',
        ]);

        $notification = Notification::create([
            'user_id' => $request->user_id,
            'message' => $request->message,
            'read'    => false,
            'date'    => now(),
            'state'   => true
        ]);

        return response()->json($notification, 201);
    }

    // Marcar notificación como leída
    public function markAsRead(Request $request)
    {
        $request->validate([
            'notification_id' => 'required|integer|exists:notifications,notification_id',
        ]);

        $notification = Notification::findOrFail($request->notification_id);
        $notification->read = true;
        $notification->save();

        return response()->json(['message' => 'Notificación marcada como leída']);
    } */
}
