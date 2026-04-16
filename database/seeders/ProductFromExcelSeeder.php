<?php

namespace Database\Seeders;

use App\Imports\ProductBusinessImport;
use App\Imports\ProductsImport;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Maatwebsite\Excel\Facades\Excel;

class ProductFromExcelSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run()
    {
        /* $productsPath = storage_path('app/seeders/products.xlsx'); */
        $businessPath = storage_path('app/seeders/productos_esperanza.xlsx');

        /* Excel::import(new ProductsImport, $productsPath); */

        Excel::import(new ProductBusinessImport, $businessPath);
        
    }
}
