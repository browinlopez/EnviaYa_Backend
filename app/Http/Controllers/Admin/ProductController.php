<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Imports\ProductsImport;
use App\Imports\ProductsPreviewImport;
use App\Models\Business;
use App\Models\Product\CarPartsProducts;
use App\Models\Product\Category;
use App\Models\Product\GroceryProduct;
use App\Models\Product\PharmacyProduct;
use App\Models\Product\Product;
use App\Models\Product\ProductBusiness;
use App\Models\Product\RestaurantProducts;
use App\Models\temp\ImportedProductTemp;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Maatwebsite\Excel\Excel;
use PhpOffice\PhpSpreadsheet\IOFactory;

class ProductController extends Controller
{
    public function index()
    {
        $products = Product::with('category', 'businesses')->get();
        return view('admin.products.index', compact('products'));
    }

    public function create()
    {
        $categories = Category::all();
        $businesses = Business::all();

        $importedProducts = ProductBusiness::with(['product', 'business', 'product.category'])
            ->latest()
            ->paginate(10);

        return view('admin.products.create', compact(
            'categories',
            'businesses',
            'importedProducts'
        ));
    }

    public function store(Request $request)
    {
        // Limpiar precio antes de validar
        if ($request->has('price')) {
            $request->merge([
                'price' => str_replace(',', '.', str_replace('.', '', $request->price))
            ]);
        }

        // Validar campos generales
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'category_id' => 'required|integer',
            'state' => 'required|boolean',
            'busines_id' => 'required|integer|exists:business,busines_id',
            'price' => 'required|numeric',
            'amount' => 'required|integer',
        ]);

        // Crear producto
        $product = Product::create($data);

        // Guardar en tabla pivote
        ProductBusiness::create([
            'busines_id' => $data['busines_id'],
            'products_id' => $product->products_id,
            'price' => $data['price'],
            'amount' => $data['amount'],
            'qualification' => 0
        ]);

        $business = Business::find($data['busines_id']);

        // 📌 Dependiendo del tipo del negocio guardar modelo correspondiente

        switch ($business->type) {

            case 1: // Grocery
                GroceryProduct::create([
                    'products_id' => $product->products_id,
                    'brand' => $request->brand,
                    'size' => $request->size,
                    'expiration_date' => $request->expiration_date,
                ]);
                break;

            case 2: // Pharmacy
                PharmacyProduct::create([
                    'products_id' => $product->products_id,
                    'active_ingredient' => $request->active_ingredient,
                    'dosage' => $request->dosage,
                    'presentation' => $request->presentation,
                    'expiration_date' => $request->expiration_date,
                ]);
                break;

            case 3: // Restaurant
                RestaurantProducts::create([
                    'products_id' => $product->products_id,
                    'food_type' => $request->food_type,
                    'portion_size' => $request->portion_size,
                    'is_vegan' => $request->is_vegan,
                    'is_gluten_free' => $request->is_gluten_free,
                    'allergens' => $request->allergens,
                ]);
                break;

            case 4: // Car parts
                CarPartsProducts::create([
                    'products_id' => $product->products_id,
                    'brand' => $request->car_brand,
                    'model' => $request->car_model,
                    'year' => $request->car_year,
                    'oem_code' => $request->oem_code,
                    'compatibility' => $request->compatibility,
                ]);
                break;
        }

        return redirect()->route('admin.products.index')
            ->with('success', 'Producto creado correctamente.');
    }


    public function edit($id)
    {
        $product = Product::with('businesses', 'grocery', 'pharmacy')->findOrFail($id);
        $businesses = Business::all();
        $categories = Category::active()->get();
        return view('admin.products.edit', compact('product', 'businesses', 'categories'));
    }

    public function update(Request $request, $id)
    {
        $product = Product::findOrFail($id);

        // Validar campos generales
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'category_id' => 'required|integer',
            'state' => 'required|boolean',
            'busines_id' => 'required|integer|exists:business,busines_id',
            'price' => 'required|numeric',
            'amount' => 'required|integer',
        ]);

        $product->update($data);

        // Actualizar pivote ProductBusiness
        $productBusiness = ProductBusiness::where('products_id', $product->products_id)
            ->where('busines_id', $product->businesses->first()?->busines_id ?? 0)
            ->first();

        if ($productBusiness) {
            $productBusiness->update([
                'busines_id' => $data['busines_id'],
                'price' => $data['price'],
                'amount' => $data['amount'],
            ]);
        }

        // Tipo de negocio actual
        $business = Business::find($data['busines_id']);

        if ($business->type == 1) {
            // Campos Grocery: solo actualizar si vienen en el request
            $groceryData = $request->only(['brand', 'size', 'expiration_date']);
            if ($product->grocery) {
                $product->grocery->update($groceryData);
            } else {
                GroceryProduct::create(array_merge($groceryData, ['products_id' => $product->products_id]));
            }

            // Eliminar Pharmacy si existía antes
            if ($product->pharmacy) {
                $product->pharmacy->delete();
            }
        } elseif ($business->type == 2) {
            // Campos Pharmacy: solo actualizar si vienen en el request
            $pharmaData = $request->only(['active_ingredient', 'dosage', 'presentation', 'expiration_date']);
            if ($product->pharmacy) {
                $product->pharmacy->update($pharmaData);
            } else {
                PharmacyProduct::create(array_merge($pharmaData, ['products_id' => $product->products_id]));
            }

            // Eliminar Grocery si existía antes
            if ($product->grocery) {
                $product->grocery->delete();
            }
        }

        return redirect()->route('admin.products.index')->with('success', 'Producto actualizado correctamente.');
    }

    public function destroy($id)
    {
        $product = Product::findOrFail($id);
        $product->delete();

        return redirect()->route('admin.products.index')->with('success', 'Producto eliminado correctamente.');
    }
}
