<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Business\CategoryBusiness;
use Illuminate\Http\Request;

class CategoryBusinessController extends Controller
{
    // Listar
    public function index()
    {
        $categories = CategoryBusiness::paginate(10);
        return view('admin.category_business.index', compact('categories'));
    }

    // Crear
    public function create()
    {
        return view('admin.category_business.create');
    }

    // Guardar
    public function store(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'image' => 'nullable|string|max:255',
        ]);

        CategoryBusiness::create($request->all());

        return redirect()->route('admin.category-business.index')
            ->with('success', 'Categoría creada correctamente.');
    }

    // Editar
    public function edit($id)
    {
        $category = CategoryBusiness::findOrFail($id);
        return view('admin.category_business.edit', compact('category'));
    }

    // Actualizar
    public function update(Request $request, $id)
    {
        $category = CategoryBusiness::findOrFail($id);

        $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'image' => 'nullable|image|mimes:jpg,jpeg,png,gif,webp|max:2048',
        ]);

        // Si suben una nueva imagen, guardarla
        if ($request->hasFile('image')) {
            $path = $request->file('image')->store('categories', 'public');
            $category->image = $path;
        }

        // Actualizar el resto de campos
        $category->name = $request->name;
        $category->description = $request->description;
        $category->save();

        return redirect()->route('admin.category-business.index')
            ->with('success', 'Categoría actualizada correctamente.');
    }


    // Eliminar
    public function destroy($id)
    {
        $category = CategoryBusiness::findOrFail($id);
        $category->delete();

        return redirect()->route('admin.category-business.index')
            ->with('success', 'Categoría eliminada correctamente.');
    }
}
