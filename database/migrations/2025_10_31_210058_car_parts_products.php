<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('car_parts_products', function (Blueprint $table) {
            $table->id('car_part_product_id');
            $table->unsignedBigInteger('products_id');
            $table->string('brand')->nullable();             // Toyota, Honda...
            $table->string('model')->nullable();             // Corolla, Civic...
            $table->string('year')->nullable();              // 2015, 2018...
            $table->string('oem_code')->nullable();          // OEM part code
            $table->string('compatibility')->nullable();     // List of compatible models
            $table->timestamps();

            $table->foreign('products_id')->references('products_id')->on('products')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('car_parts_products');
    }
};
