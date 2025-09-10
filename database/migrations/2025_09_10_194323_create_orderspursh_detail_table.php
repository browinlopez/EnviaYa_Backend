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
        Schema::create('orderspursh_detail', function (Blueprint $table) {
            $table->increments('orderDet_id');
            $table->unsignedInteger('orderPursh_id')->nullable(); // FK a orderspurchase
            $table->unsignedBigInteger('product_id')->nullable(); // FK a products
            $table->integer('quantity')->nullable();
            $table->decimal('purchase_price', 10, 2);

            // índices + FKs
            $table->index('orderPursh_id', 'fk_orderspursh_detail_order');
            $table->index('product_id', 'fk_orderspursh_detail_product');

            $table->foreign('orderPursh_id', 'fk_orderspursh_detail_order')
                ->references('orderPursh_id')->on('orderspurchase')
                ->onDelete('cascade');

            $table->foreign('product_id', 'fk_orderspursh_detail_product')
                ->references('products_id')->on('products')
                ->onDelete('set null');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('orderspursh_detail');
    }
};
