<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Business;
use App\Models\Buyer\Buyer;
use App\Models\Buyer\ResidentialComplex;
use App\Models\Domiciliary;
use App\Models\Owner\Owner;
use App\Models\Product\Category;
use App\Models\Product\Product;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DashboardController extends Controller
{
    public function index()
    {
        $domiciliariesCount = Domiciliary::count();
        $buyersCount = Buyer::count();
        $ownersCount = Owner::count();
        $businessCount = Business::count();
        $complexCount = ResidentialComplex::count();
        $productsCount = Product::count();

        // Negocios por tipo
        $businessTypes = Business::select('type', DB::raw('count(*) as total'))
            ->groupBy('type')
            ->pluck('total', 'type');

        // Productos por categoría
        $productCategories = Product::select('category_id', DB::raw('count(*) as total'))
            ->groupBy('category_id')
            ->pluck('total', 'category_id');

        $categories = Category::pluck('name', 'category_id');

        // Negocios con más pedidos (top 5)
        $topBusinesses = Business::withCount('orders')
            ->orderByDesc('orders_count')
            ->limit(5)
            ->get();

        // Productos más vendidos (top 5)
        $topProducts = Product::with(['business']) // 👈 aquí cargas el negocio
            ->withSum('salesDetails as total_sold', 'amount')
            ->orderByDesc('total_sold')
            ->limit(5)
            ->get();


        return view('dashboard', compact(
            'domiciliariesCount',
            'buyersCount',
            'ownersCount',
            'businessCount',
            'complexCount',
            'productsCount',
            'businessTypes',
            'productCategories',
            'categories',
            'topBusinesses',
            'topProducts'
        ));
    }
}
