<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('grocery_products', function (Blueprint $table) {
            $table->increments('grocery_product_id'); // PK autoincremental
            $table->unsignedBigInteger('products_id'); // FK hacia products
            $table->string('brand', 255)->nullable();
            $table->string('size', 100)->nullable();
            $table->date('expiration_date')->nullable();

            // Índice para products_id
            $table->index('products_id');

            // Foreign Key
            $table->foreign('products_id', 'grocery_products_ibfk_1')
                  ->references('products_id')->on('products')
                  ->onDelete('cascade'); // opcional
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('grocery_products');
    }
};
