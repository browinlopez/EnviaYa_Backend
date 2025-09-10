<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up()
    {
        Schema::create('pharmacy_products', function (Blueprint $table) {
            $table->id('pharmacy_product_id');
            $table->unsignedBigInteger('products_id');
            $table->string('active_ingredient', 255)->nullable();
            $table->string('dosage', 100)->nullable();
            $table->string('presentation', 255)->nullable();
            $table->date('expiration_date')->nullable();
            $table->foreign('products_id')->references('products_id')->on('products')->onDelete('cascade');
        });
    }


    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('pharmacy_products');
    }
};
