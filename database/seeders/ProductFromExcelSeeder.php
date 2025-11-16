<?php

namespace Database\Seeders;

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
        $path = storage_path('app/seeders/products.xlsx');

        Excel::import(new ProductsImport, $path);
    }
}
