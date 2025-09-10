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
        Schema::create('orderssales_detail', function (Blueprint $table) {
            $table->increments('orderDet_id');
            $table->unsignedInteger('orderSales_id')->nullable(); // FK a orderssales
            $table->unsignedBigInteger('product_id')->nullable(); // FK a products
            $table->integer('amount');
            $table->decimal('unit_price', 10, 2);

            // índices + FKs
            $table->index('orderSales_id', 'fk_orderssales_detail_order');
            $table->index('product_id', 'fk_orderssales_detail_product');

            $table->foreign('orderSales_id', 'fk_orderssales_detail_order')
                ->references('orderSales_id')->on('orderssales')
                ->onDelete('cascade');

            $table->foreign('product_id', 'fk_orderssales_detail_product')
                ->references('products_id')->on('products')
                ->onDelete('set null');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('orderssales_detail');
    }
};
