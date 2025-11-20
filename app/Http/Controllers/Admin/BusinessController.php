<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Business;
use App\Models\Business\CategoryBusiness;
use App\Models\Domiciliary;
use App\Models\Product\Product;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class BusinessController extends Controller
{
    public function index()
    {
        $businesses = Business::with('category')->paginate(10);
        return view('admin.negocios.index', compact('businesses'));
    }

    public function create()
    {
        $categories = CategoryBusiness::all();
        $products = Product::all();
        $domiciliaries = Domiciliary::all();
        $business = null;
        return view('admin.negocios.create', compact('categories', 'products', 'domiciliaries', 'business'));
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => 'required|string',
            'phone' => 'nullable|string',
            'address' => 'nullable|string',
            'NIT' => 'nullable|string',
            'razonSocial_DCD' => 'nullable|string',
            'type' => 'required|integer',
            'state' => 'nullable|string',
            'logo' => 'nullable|image'
        ]);

        // SUBIR LOGO
        if ($request->hasFile('logo')) {

            // Guarda en storage/app/public/Negocios
            $path = $request->file('logo')->store('Negocios', 'public');
            $data['logo'] = Storage::url($path);
        }

        // Crear negocio
        $business = Business::create($data);

        // Relaciones
        $business->products()->sync($request->input('products', []));
        $business->domiciliaries()->sync($request->input('domiciliaries', []));

        return redirect()->route('admin.negocios.index')->with('success', 'Negocio creado');
    }

    public function edit(Business $business)
    {
        $categories = CategoryBusiness::all();
        $domiciliaries = Domiciliary::all();

        $business->load(['products', 'domiciliaries', 'category']);

        // Solo productos afiliados
        $businessProducts = $business->products;

        // si los necesitas en otras partes de la vista
        $allProducts = Product::all();

        return view('admin.negocios.edit', compact(
            'business',
            'categories',
            'domiciliaries',
            'businessProducts',
            'allProducts'
        ));
    }

    public function update(Request $request, Business $business)
    {
        $data = $request->validate([
            'name' => 'required|string',
            'phone' => 'nullable|string',
            'address' => 'nullable|string',
            'municipality_id' => 'required|integer',
            'NIT' => 'nullable|string',
            'razonSocial_DCD' => 'nullable|string',
            'type' => 'required|integer',
            'state' => 'nullable|string',
            'logo' => 'nullable|image'
        ]);

        // Si sube una nueva imagen
        if ($request->hasFile('logo')) {

            // Eliminar la imagen anterior
            if ($business->logo) {
                Storage::disk('public')->delete($business->logo);
            }

            // Guardar nueva imagen -> solo path interno
            $path = $request->file('logo')->store('logos', 'public');

            // Guardar solo el path interno en BD
            $data['logo'] = $path;
        }

        // Actualizar datos
        $business->update($data);

        // Relaciones
        $business->products()->sync($request->input('products', []));
        $business->domiciliaries()->sync($request->input('domiciliaries', []));

        return redirect()->route('admin.negocios.index')
            ->with('success', 'Negocio actualizado');
    }

    public function destroy(Business $business)
    {
        $business->delete();
        return back()->with('success', 'Negocio eliminado');
    }
}
