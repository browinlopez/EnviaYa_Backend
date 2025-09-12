<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Business;
use App\Models\Domiciliary;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class DomiciliaryController extends Controller
{
    public function index()
    {
        $domiciliaries = Domiciliary::with('user')->paginate(10);
        return view('admin.domiciliarios.index', compact('domiciliaries'));
    }

    public function create()
    {
        $users = User::all();
        $businesses = Business::all(); // Traemos todos los negocios
        return view('admin.domiciliarios.create', compact( 'users', 'businesses'));
    }

    public function store(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|string|email|unique:user,email',
            'password' => 'required|string|min:6',
            'phone' => 'nullable|string|max:20',
            'address' => 'nullable|string|max:225',
            'document' => 'nullable|string|max:225',
            'available' => 'nullable|boolean',
            'qualification' => 'nullable|numeric|min:0|max:5',
            'state' => 'nullable|boolean',
        ]);

        $user = User::create([
            'name' => $request->name,
            'email' => $request->email,
            'password' => Hash::make($request->password),
            'phone' => $request->phone,
            'address' => $request->address,
            'rol' => 3, // domiciliario
            'qualification' => $request->qualification ?? 0,
            'state' => $request->state ?? 1
        ]);

        Domiciliary::create([
            'user_id' => $user->user_id,
            'available' => $request->available ?? false,
            'document' => $request->document,
            'qualification' => $user->qualification,
            'state' => $user->state
        ]);

        return redirect()->route('admin.domiciliarios.index')
            ->with('success', 'Domiciliario creado correctamente.');
    }

    // Editar
    public function edit($id)
    {
        $domiciliary = Domiciliary::with('businesses')->findOrFail($id);
        $users = User::all();
        $businesses = Business::all(); // Traemos todos los negocios
        return view('admin.domiciliarios.edit', compact('domiciliary', 'users', 'businesses'));
    }

    // Actualizar
    public function update(Request $request, $id)
    {
        $domiciliary = Domiciliary::with('businesses', 'user')->findOrFail($id);

        $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|unique:user,email,' . $domiciliary->user_id . ',user_id',
            'password' => 'nullable|string|min:6',
            'phone' => 'nullable|string|max:20',
            'address' => 'nullable|string|max:225',
            'available' => 'required|boolean',
            'qualification' => 'nullable|numeric|min:0|max:5',
            'document' => 'nullable|string|max:225',
            'state' => 'required|boolean',
            'business_id' => 'nullable|exists:business,busines_id',
        ]);

        // Actualizar usuario
        $domiciliary->user->update([
            'name' => $request->name,
            'email' => $request->email,
            'password' => $request->password ? Hash::make($request->password) : $domiciliary->user->password,
            'phone' => $request->phone,
            'address' => $request->address,
        ]);

        // Actualizar domiciliario
        $domiciliary->update([
            'available' => $request->available,
            'qualification' => $request->qualification,
            'document' => $request->document,
            'state' => $request->state,
        ]);

        // Asignar un solo negocio
        $domiciliary->businesses()->sync($request->business_id ? [$request->business_id] : []);

        return redirect()->route('admin.domiciliarios.index')
            ->with('success', 'Domiciliario actualizado correctamente.');
    }



    public function destroy($id)
    {
        $domiciliary = Domiciliary::findOrFail($id);
        $domiciliary->delete();

        return redirect()->route('admin.domiciliarios.index')
            ->with('success', 'Domiciliario eliminado correctamente.');
    }

    public function show($id)
    {
        $domiciliary = Domiciliary::with('user', 'reviews', 'businesses')->findOrFail($id);
        return view('admin.domiciliarios.show', compact('domiciliary'));
    }
}
