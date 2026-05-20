<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ResidentialComplex;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ResidentialComplexController extends Controller
{
    public function index(Request $request)
    {
        // Si la petición es JSON (AJAX desde tu tabla moderna)
        if ($request->wantsJson()) {

            $perPage = 10;

            // Construimos la query
            $query = DB::table('residential_complexes')
                ->select('complex_id','name','address','state','people_count');

            // Filtro de búsqueda por nombre
            if ($request->filled('search')) {
                $query->where('name', 'like', $request->search.'%');
            }

            // Filtro de estado (Activo/Inactivo)
            if ($request->filled('state') && in_array($request->state, ['0','1'])) {
                $query->where('state', $request->state);
            }

            // Orden y paginación
            $complexes = $query->orderBy('complex_id')
                               ->paginate($perPage);

            // Retornamos JSON compatible con tu JS
            return response()->json($complexes);
        }

        // Vista normal para Blade (carga inicial de la página)
        return view('admin.conjuntos.index');
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

        $data['people_count'] = $data['people_count'] ?? 0;

        ResidentialComplex::create($data);

        return redirect()->route('admin.conjuntos.index')
                         ->with('success', 'Conjunto residencial creado correctamente.');
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

        return redirect()->route('admin.conjuntos.index')
                         ->with('success', 'Conjunto residencial actualizado correctamente.');
    }

    public function destroy($id)
    {
        $complex = ResidentialComplex::findOrFail($id);
        $complex->delete();

        return redirect()->route('admin.conjuntos.index')
                         ->with('success', 'Conjunto residencial eliminado correctamente.');
    }
}
