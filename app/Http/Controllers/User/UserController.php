<?php

namespace App\Http\Controllers\User;

use App\Http\Controllers\Controller;
use App\Models\Buyer\Buyer;
use App\Models\User;
use App\Models\User\UserAddress;
use Illuminate\Http\Request;

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
    public function update(Request $request)
    {
        $request->validate([
            'user_id'       => 'required|integer|exists:user,user_id',
            'name'          => 'nullable|string|max:255',
            'email'         => 'nullable|email|unique:user,email,' . $request->user_id . ',user_id',
            'phone'         => 'nullable|string|max:20',
            'address'       => 'nullable|string|max:225',
            'rol'           => 'nullable|integer|exists:rol,rol_id',
            'qualification' => 'nullable|numeric|between:0,5',
            'state'         => 'nullable|boolean'
        ]);

        $user = User::findOrFail($request->user_id);
        $user->update($request->only([
            'name',
            'email',
            'phone',
            'address',
            'rol',
            'qualification',
            'state'
        ]));

        return response()->json($user);
    }

    // Eliminar usuario
    public function destroy(Request $request)
    {
        $request->validate([
            'user_id' => 'required|integer|exists:user,user_id',
        ]);

        $user = User::findOrFail($request->user_id);
        $user->delete();

        return response()->json(['message' => 'Usuario eliminado correctamente']);
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
            'municipality_id' => 'required|integer|exists:municipalities,id',
            'alias_id' => 'nullable|integer|exists:alias,alias_id',
            'latitude' => 'nullable|numeric',
            'longitude' => 'nullable|numeric'
        ]);

        $address = UserAddress::create([
            'user_id' => $request->user_id,
            'address' => $request->address,
            'municipality_id' => $request->municipality_id,
            'alias_id' => $request->alias_id,
            'latitude' => $request->latitude,
            'longitude' => $request->longitude,
            'state' => true
        ]);

        return response()->json($address, 201);
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
