<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Business;
use App\Models\Business\CategoryBusiness;
use App\Models\Domiciliary;
use App\Models\Product\Product;
use Illuminate\Http\Request;

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
        return view('admin.negocios.create', compact('categories','products','domiciliaries'));
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => 'required|string',
            'phone' => 'nullable|string',
            'address' => 'nullable|string',
            //'municipality_id' => 'required|integer',
            'NIT' => 'nullable|string',
            'razonSocial_DCD' => 'nullable|string',
            'type' => 'required|integer', // categoría
            'state' => 'nullable|string',
            'logo' => 'nullable|image'
        ]);

        // subir logo si hay
        if($request->hasFile('logo')){
            $data['logo'] = $request->file('logo')->store('logos','public');
        }

        $business = Business::create($data);

        // relaciones
        $business->products()->sync($request->input('products', []));
        $business->domiciliaries()->sync($request->input('domiciliaries', []));

        return redirect()->route('admin.negocios.index')->with('success','Negocio creado');
    }

    public function edit(Business $business)
    {
        $categories = CategoryBusiness::all();
        $products = Product::all();
        $domiciliaries = Domiciliary::all();

        // con relaciones
        $business->load('products','domiciliaries','category');

        return view('admin.negocios.edit', compact('business','categories','products','domiciliaries'));
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

        if($request->hasFile('logo')){
            $data['logo'] = $request->file('logo')->store('logos','public');
        }

        $business->update($data);
        $business->products()->sync($request->input('products', []));
        $business->domiciliaries()->sync($request->input('domiciliaries', []));

        return redirect()->route('admin.negocios.index')->with('success','Negocio actualizado');
    }

    public function destroy(Business $business)
    {
        $business->delete();
        return back()->with('success','Negocio eliminado');
    }
}
