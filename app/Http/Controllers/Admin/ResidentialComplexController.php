<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Buyer\ResidentialComplex;
use Illuminate\Http\Request;

class ResidentialComplexController extends Controller
{
    public function index()
    {
        $complexes = ResidentialComplex::all(); // para DataTables client-side
        return view('admin.conjuntos.index', compact('complexes'));
    }

    public function create()
    {
        return view('admin.conjuntos.create');
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'address' => 'nullable|string|max:255',
            'state' => 'nullable|boolean',
            'people_count' => 'nullable|integer',
        ]);

        // Si no se envía people_count, asignamos 0
        $data['people_count'] = $data['people_count'] ?? 0;

        ResidentialComplex::create($data);

        return redirect()->route('admin.conjuntos.index')->with('success', 'Conjunto residencial creado correctamente.');
    }

    public function edit($id)
    {
        $complex = ResidentialComplex::findOrFail($id);
        return view('admin.conjuntos.edit', compact('complex'));
    }

    public function update(Request $request, $id)
    {
        $complex = ResidentialComplex::findOrFail($id);

        $data = $request->validate([
            'name' => 'required|string|max:255',
            'address' => 'nullable|string|max:255',
            'state' => 'nullable|boolean',
            'people_count' => 'nullable|integer',
        ]);

        $complex->update($data);

        return redirect()->route('admin.conjuntos.index')->with('success', 'Conjunto residencial actualizado correctamente.');
    }

    public function destroy($id)
    {
        $complex = ResidentialComplex::findOrFail($id);
        $complex->delete();

        return redirect()->route('admin.conjuntos.index')->with('success', 'Conjunto residencial eliminado correctamente.');
    }
}
