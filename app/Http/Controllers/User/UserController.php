<?php

namespace App\Http\Controllers\User;

use App\Http\Controllers\Controller;
use App\Models\Buyer;
use App\Models\User;
use App\Models\User\UserAddress;
use Illuminate\Http\Request;

class UserController extends Controller
{
    // Listar todos los usuarios con relaciones
    public function index()
    {
        $users = User::with(['buyer', 'addresses'])->get();
        return response()->json($users);
    }

    // Detalle de un usuario
    public function show(Request $request)
    {
        $request->validate([
            'user_id' => 'required|integer|exists:user,user_id',
        ]);

        $user = User::with(['buyer', 'addresses'])->findOrFail($request->user_id);
        return response()->json($user);
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
            'name', 'email', 'phone', 'address', 'rol', 'qualification', 'state'
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

    // Obtener direcciones del usuario
    public function getAddresses(Request $request)
    {
        $request->validate([
            'user_id' => 'required|integer|exists:user,user_id',
        ]);

        $addresses = UserAddress::where('user_id', $request->user_id)
                        ->with(['city', 'alias'])
                        ->get();

        return response()->json($addresses);
    }

    // Agregar dirección a un usuario
    public function addAddress(Request $request)
    {
        $request->validate([
            'user_id' => 'required|integer|exists:user,user_id',
            'address' => 'required|string|max:225',
            'city_id' => 'required|integer|exists:city,city_id',
            'alias_id'=> 'nullable|integer|exists:alias,alias_id'
        ]);

        $address = UserAddress::create([
            'user_id' => $request->user_id,
            'address' => $request->address,
            'city_id' => $request->city_id,
            'alias_id'=> $request->alias_id,
            'state'   => true
        ]);

        return response()->json($address, 201);
    }

    // Obtener perfil buyer del usuario
    public function getBuyerProfile(Request $request)
    {
        $request->validate([
            'user_id' => 'required|integer|exists:user,user_id',
        ]);

        $buyer = Buyer::where('user_id', $request->user_id)->first();

        if (!$buyer) {
            return response()->json(['message' => 'El usuario no tiene perfil comprador'], 404);
        }

        return response()->json($buyer);
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
