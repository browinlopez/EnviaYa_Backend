<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Buyer\Buyer;
use App\Models\Buyer\BuyerComplex;
use App\Models\Buyer\ResidentialComplex;
use App\Models\User;
use Illuminate\Http\Request;

class BuyerController extends Controller
{
    public function index()
    {
        $buyers = Buyer::with(['user', 'residentialComplexes'])->paginate(10);
        return view('admin.compradores.index', compact('buyers'));
    }


    public function create()
    {
        $users = User::all();
        $complexes = ResidentialComplex::all();
        return view('admin.compradores.create', compact('users', 'complexes'));
    }

    public function store(Request $request)
    {
        // Usamos la misma validación que en update
        $validated = $request->validate([
            'user_id'            => 'required|exists:user,user_id',  // ajusta tabla si es plural
            'qualification'      => 'nullable|numeric',
            'state'              => 'required|boolean',              // si es string cámbialo a string
            'belongs_to_complex' => 'required|boolean',
            'complex_id'         => 'nullable|exists:residential_complexes,complex_id'
        ]);

        // Crear comprador
        $buyer = Buyer::create([
            'user_id'            => $validated['user_id'],
            'qualification'      => $validated['qualification'] ?? null,
            'state'              => $validated['state'],
            'belongs_to_complex' => $validated['belongs_to_complex'],
        ]);

        // Crear relación con complejo si aplica
        if ($validated['belongs_to_complex'] && !empty($validated['complex_id'])) {
            BuyerComplex::create([
                'buyer_id'   => $buyer->buyer_id,
                'complex_id' => $validated['complex_id'],
            ]);
        }

        return redirect()->route('admin.compradores.index')->with('success', 'Comprador creado correctamente');
    }

    public function edit(Buyer $compradore)
    {
        $users = User::all();
        $complexes = ResidentialComplex::all();
        return view('admin.compradores.edit', compact('compradore', 'users', 'complexes'));
    }

    public function update(Request $request, $id)
    {
        $validated = $request->validate([
            'qualification'      => 'nullable|numeric',
            'state'              => 'required|boolean', // si es string cámbialo
            'belongs_to_complex' => 'required|boolean',
            'complex_id' => 'nullable|exists:residential_complexes,complex_id'
        ]);

        $buyer = Buyer::with('user')->findOrFail($id);

        // Actualizar comprador
        $buyer->update([
            'qualification'      => $validated['qualification'] ?? null,
            'state'              => $validated['state'],
            'belongs_to_complex' => $validated['belongs_to_complex'],
        ]);

        // Actualizar usuario asociado
        $buyer->user->update([
            'name'    => $request->user_name,
            'email'   => $request->user_email,
            'phone'   => $request->user_phone,
            'address' => $request->user_address,
        ]);

        // Actualizar relación con complejo
        BuyerComplex::where('buyer_id', $buyer->buyer_id)->delete();

        if ($validated['belongs_to_complex'] && !empty($validated['complex_id'])) {
            BuyerComplex::create([
                'buyer_id'   => $buyer->buyer_id,
                'complex_id' => $validated['complex_id'],
            ]);
        }

        return redirect()->route('admin.compradores.index')->with('success', 'Comprador actualizado correctamente');
    }



    public function destroy(Buyer $compradore)
    {
        $compradore->delete();
        return redirect()->route('admin.compradores.index')->with('success', 'Comprador eliminado');
    }
}
