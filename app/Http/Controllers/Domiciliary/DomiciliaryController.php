<?php

namespace App\Http\Controllers\Domiciliary;

use App\Http\Controllers\Controller;
use App\Models\Domiciliary;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class DomiciliaryController extends Controller
{
    // Listar todos los domiciliarios
    public function listDomiciliary(Request $request)
    {
        $domiciliaries = Domiciliary::with('user', 'reviews')->get();
        return response()->json($domiciliaries);
    }

    // Crear un domiciliario
    public function createDomiciliary(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|string|email|unique:user,email',
            'password' => 'required|string|min:6',
            'phone' => 'nullable|string|max:20',
            'address' => 'nullable|string|max:225',
            'available' => 'boolean',
            'qualification' => 'numeric|min:0|max:5',
            'state' => 'boolean'
        ]);

        // Crear el usuario con rol 3 (Domiciliario)
        $user = User::create([
            'name' => $request->name,
            'email' => $request->email,
            'password' => Hash::make($request->password),
            'phone' => $request->phone,
            'address' => $request->address,
            'rol' => 3,
            'qualification' => $request->qualification ?? 0,
            'state' => $request->state
        ]);

        // Crear el domiciliario vinculado al usuario
        $domiciliary = Domiciliary::create([
            'user_id' => $user->user_id,
            'available' => $request->available ?? false,
            'qualification' => $user->qualification,
            'state' => $user->state
        ]);

        return response()->json([
            'message' => 'Usuario y domiciliario creados correctamente',
            'user' => $user,
            'domiciliary' => $domiciliary
        ]);
    }


    // Actualizar un domiciliario
    public function updateDomiciliary(Request $request)
    {
        $request->validate([
            'domiciliary_id' => 'required|integer|exists:domiciliary,domiciliary_id',
            'user_id' => 'integer|exists:user,user_id',
            'available' => 'boolean',
            'qualification' => 'numeric|min:0|max:5',
            'state' => 'boolean'
        ]);

        $domiciliary = Domiciliary::find($request->domiciliary_id);
        $domiciliary->update($request->all());

        return response()->json(['message' => 'Domiciliario actualizado', 'domiciliary' => $domiciliary]);
    }

    // Eliminar un domiciliario
    public function deleteDomiciliary(Request $request)
    {
        $request->validate([
            'domiciliary_id' => 'required|integer|exists:domiciliary,domiciliary_id',
        ]);

        $domiciliary = Domiciliary::find($request->domiciliary_id);
        $domiciliary->delete();

        return response()->json(['message' => 'Domiciliario eliminado']);
    }

    // Obtener un domiciliario específico
    public function showDomiciliary(Request $request)
    {
        $request->validate([
            'domiciliary_id' => 'required|integer|exists:domiciliary,domiciliary_id',
        ]);

        $domiciliary = Domiciliary::with('user', 'reviews')->find($request->domiciliary_id);

        return response()->json($domiciliary);
    }
}
