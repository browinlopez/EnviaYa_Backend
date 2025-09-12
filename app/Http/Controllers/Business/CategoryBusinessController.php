<?php

namespace App\Http\Controllers\Business;

use App\Http\Controllers\Controller;
use App\Models\Business\CategoryBusiness;
use Illuminate\Http\Request;

class CategoryBusinessController extends Controller
{
    public function index(Request $request)
    {
        return response()->json(CategoryBusiness::all());
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
        ]);

        $category = CategoryBusiness::create($validated);

        return response()->json($category, 201);
    }

    public function show(Request $request)
    {
        $validated = $request->validate([
            'id' => 'required|integer|exists:category_business,id'
        ]);

        $category = CategoryBusiness::findOrFail($validated['id']);

        return response()->json($category);
    }

    public function update(Request $request)
    {
        $validated = $request->validate([
            'id' => 'required|integer|exists:category_business,id',
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
        ]);

        $category = CategoryBusiness::findOrFail($validated['id']);
        $category->update([
            'name' => $validated['name'],
            'description' => $validated['description'] ?? null,
        ]);

        return response()->json($category);
    }

    public function destroy(Request $request)
    {
        $validated = $request->validate([
            'id' => 'required|integer|exists:category_business,id'
        ]);

        $category = CategoryBusiness::findOrFail($validated['id']);
        $category->delete();

        return response()->json(null, 204);
    }

}
