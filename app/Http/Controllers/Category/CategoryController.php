<?php

namespace App\Http\Controllers\Category;

use App\Http\Controllers\Controller;
use App\Models\Product\Category;
use Illuminate\Http\Request;

class CategoryController extends Controller
{
    // Listar todas las categorías
    public function index(Request $request)
    {
        $categories = Category::all(); // o ->get()
        return response()->json($categories);
    }


    // Crear nueva categoría
    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'state' => 'nullable|boolean'
        ]);

        $category = Category::create($validated);

        return response()->json([
            'message' => 'Categoría creada correctamente',
            'category' => $category
        ], 201);
    }

    // Mostrar categoría específica
    public function show(Request $request)
    {
        $validated = $request->validate([
            'category_id' => 'required|integer|exists:category,category_id',
        ]);

        $category = Category::findOrFail($validated['category_id']);

        return response()->json($category);
    }

    // Actualizar categoría
    public function update(Request $request)
    {
        $validated = $request->validate([
            'category_id' => 'required|integer|exists:category,category_id',
            'name' => 'nullable|string|max:255',
            'description' => 'nullable|string',
            'state' => 'nullable|boolean'
        ]);

        $category = Category::findOrFail($validated['category_id']);
        $category->update($request->only(['name', 'description', 'state']));

        return response()->json([
            'message' => 'Categoría actualizada correctamente',
            'category' => $category
        ]);
    }

    // Eliminar categoría
    public function destroy(Request $request)
    {
        $validated = $request->validate([
            'category_id' => 'required|integer|exists:category,category_id',
        ]);

        $category = Category::findOrFail($validated['category_id']);
        $category->delete();

        return response()->json(['message' => 'Categoría eliminada correctamente']);
    }
}
