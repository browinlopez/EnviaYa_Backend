<?php

namespace App\Http\Controllers\Category;

use App\Http\Controllers\Controller;
use App\Models\Product\Category;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CategoryController extends Controller
{
    // Listar todas las categorías
    public function index(Request $request)
    {
        // Validar que venga el parámetro type
        $request->validate([
            'type' => 'required|integer|exists:category_business,id'
        ]);

        $businessType = $request->type;

        // Filtrar categorías según el tipo de negocio.
        // La relación es de muchos a muchos (`category_category_business`):
        // "Bebidas" puede servir a la vez para tiendas y para restaurantes.
        $categories = Category::whereIn(
            'category_id',
            DB::table('category_category_business')
                ->where('business_category_id', $businessType)
                ->pluck('category_id')
        )->get();

        return response()->json([
            'status' => true,
            'message' => 'Categorías filtradas correctamente',
            'data' => $categories
        ]);
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
