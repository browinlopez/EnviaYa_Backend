<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Buyer\Buyer;
use App\Models\User;
use Illuminate\Http\Request;

class BuyerController extends Controller
{
    public function index()
    {
        $buyers = Buyer::with('user')->paginate(10);
        return view('admin.compradores.index', compact('buyers'));
    }

    public function create()
    {
        $users = User::all();
        return view('admin.compradores.create', compact('users'));
    }

    public function store(Request $request)
    {
        $request->validate([
            'user_id' => 'required|exists:users,user_id',
            //'qualification' => 'nullable|numeric',
            'state' => 'required|string',
            'belongs_to_complex' => 'nullable|boolean'
        ]);

        Buyer::create($request->all());

        return redirect()->route('compradores.index')->with('success', 'Comprador creado');
    }

    public function edit(Buyer $compradore)
    {
        $users = User::all();
        return view('admin.compradores.edit', compact('compradore', 'users'));
    }

    public function update(Request $request, $id)
    {
        $buyer = Buyer::with('user')->findOrFail($id);

        // Actualizar comprador
        $buyer->update([
            'qualification' => $request->qualification,
            'state' => $request->state,
            'belongs_to_complex' => $request->belongs_to_complex,
        ]);

        // Actualizar usuario asociado
        $buyer->user->update([
            'name' => $request->user_name,
            'email' => $request->user_email,
            'phone' => $request->user_phone,
            'address' => $request->user_address,
        ]);

        return redirect()->route('compradores.index')
            ->with('success', 'Comprador actualizado correctamente');
    }


    public function destroy(Buyer $compradore)
    {
        $compradore->delete();
        return redirect()->route('compradores.index')->with('success', 'Comprador eliminado');
    }
}
