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
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Storage;

class ProductController extends Controller
{
    public function index()
    {
        return view('admin.products.index');
    }

    public function indexAjax(Request $request)
    {
        $page      = (int) $request->get('page', 1);
        $businesId = $request->get('busines_id');
        $search    = trim($request->get('search'));

        $products = Product::with([
            'category',
            'businesses',
            'productBusinesses'
        ])
            ->when($businesId, function ($q) use ($businesId) {
                // Usamos la tabla pivot para evitar ambigüedad
                $q->whereHas('productBusinesses', function ($b) use ($businesId) {
                    $b->where('products_business.busines_id', $businesId);
                });
            })
            ->when($search, function ($q) use ($search) {
                $q->where('name', 'LIKE', "%{$search}%");
            })
            ->orderByDesc('products_id')
            ->paginate(10);

        return response()->json([
            'data' => $products->items(),
            'current_page' => $products->currentPage(),
            'last_page' => $products->lastPage(),
        ]);
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
        // Limpiar precio
        if ($request->has('price')) {
            $request->merge([
                'price' => str_replace(',', '.', str_replace('.', '', $request->price))
            ]);
        }

        // Validación
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'category_id' => 'required|integer',
            'state' => 'required|boolean',
            'busines_id' => 'required|integer|exists:business,busines_id',
            'price' => 'required|numeric',
            'amount' => 'required|integer',
            'product_image' => 'nullable|image|max:2048',
        ]);

        // Crear producto
        $product = Product::create($data);

        // Pivote
        ProductBusiness::create([
            'busines_id' => $data['busines_id'],
            'products_id' => $product->products_id,
            'price' => $data['price'],
            'amount' => $data['amount'],
            'qualification' => 0
        ]);

        $business = Business::find($data['busines_id']);

        /* ================= IMAGEN PRODUCTO ================= */
        if ($request->hasFile('product_image')) {

            $folder = 'products/' . Str::slug($business->name);
            $ext = $request->file('product_image')->getClientOriginalExtension();
            $fileName = 'product_' . $product->products_id . '.' . $ext;

            $request->file('product_image')
                ->storeAs($folder, $fileName, 'public');

            $product->update([
                'image' => $folder . '/' . $fileName
            ]);
        }

        /* ================= MODELOS POR TIPO ================= */
        switch ($business->type) {

            case 1:
                GroceryProduct::create([
                    'products_id' => $product->products_id,
                    'brand' => $request->brand,
                    'size' => $request->size,
                    'expiration_date' => $request->expiration_date,
                ]);
                break;

            case 2:
                PharmacyProduct::create([
                    'products_id' => $product->products_id,
                    'active_ingredient' => $request->active_ingredient,
                    'dosage' => $request->dosage,
                    'presentation' => $request->presentation,
                    'expiration_date' => $request->expiration_date,
                ]);
                break;

            case 3:
                RestaurantProducts::create([
                    'products_id' => $product->products_id,
                    'food_type' => $request->food_type,
                    'portion_size' => $request->portion_size,
                    'is_vegan' => $request->is_vegan,
                    'is_gluten_free' => $request->is_gluten_free,
                    'allergens' => $request->allergens,
                ]);
                break;

            case 4:
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

        $this->clearProductsCache();

        return redirect()
            ->route('admin.products.index')
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

        $data = $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'category_id' => 'required|integer',
            'state' => 'required|boolean',
            'busines_id' => 'required|integer|exists:business,busines_id',
            'price' => 'required|numeric',
            'amount' => 'required|integer',
            'product_image' => 'nullable|image|max:2048',
        ]);

        $product->update($data);

        // Pivot
        $productBusiness = ProductBusiness::where('products_id', $product->products_id)
            ->first();

        if ($productBusiness) {
            $productBusiness->update([
                'busines_id' => $data['busines_id'],
                'price' => $data['price'],
                'amount' => $data['amount'],
            ]);
        }

        $business = Business::find($data['busines_id']);

        /* ================= ACTUALIZAR IMAGEN ================= */
        if ($request->hasFile('product_image')) {

            // borrar imagen anterior
            if ($product->image && Storage::disk('public')->exists($product->image)) {
                Storage::disk('public')->delete($product->image);
            }

            $folder = 'products/' . Str::slug($business->name);
            $ext = $request->file('product_image')->getClientOriginalExtension();
            $fileName = 'product_' . $product->products_id . '.' . $ext;

            $request->file('product_image')
                ->storeAs($folder, $fileName, 'public');

            $product->update([
                'image' => $folder . '/' . $fileName
            ]);
        }

        /* ================= MODELOS POR TIPO ================= */
        if ($business->type == 1) {

            $groceryData = $request->only(['brand', 'size', 'expiration_date']);

            $product->grocery
                ? $product->grocery->update($groceryData)
                : GroceryProduct::create(array_merge($groceryData, [
                    'products_id' => $product->products_id
                ]));

            optional($product->pharmacy)->delete();
        } elseif ($business->type == 2) {

            $pharmaData = $request->only(['active_ingredient', 'dosage', 'presentation', 'expiration_date']);

            $product->pharmacy
                ? $product->pharmacy->update($pharmaData)
                : PharmacyProduct::create(array_merge($pharmaData, [
                    'products_id' => $product->products_id
                ]));

            optional($product->grocery)->delete();
        }

        $this->clearProductsCache();

        return redirect()
            ->route('admin.products.index')
            ->with('success', 'Producto actualizado correctamente.');
    }

    public function destroy($id)
    {
        $product = Product::findOrFail($id);

        // 🧹 Eliminar imagen física si existe
        if ($product->image && Storage::disk('public')->exists($product->image)) {
            Storage::disk('public')->delete($product->image);
        }

        $product->delete();

        $this->clearProductsCache();

        return redirect()
            ->route('admin.products.index')
            ->with('success', 'Producto eliminado correctamente.');
    }

    private function clearProductsCache(): void
    {
        Cache::flush(); // válido si solo cacheas productos
    }
}
