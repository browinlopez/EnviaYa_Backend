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
        Schema::create('orders_promotions', function (Blueprint $table) {
            $table->increments('promOrd_id'); // PK autoincremental

            $table->unsignedInteger('orderSales_id'); // FK a orders_sales
            $table->unsignedBigInteger('promotion_id')->nullable(); // FK a promotions

            $table->tinyInteger('state')->nullable();

            // Índices
            $table->index('orderSales_id', 'fk_orders_promotions_ordersales');
            $table->index('promotion_id', 'fk_orders_promotions_promotion');

            // Foreign Keys
            $table->foreign('orderSales_id', 'fk_orders_promotions_ordersales')
                  ->references('orderSales_id')->on('orderssales')
                  ->onDelete('cascade');

            $table->foreign('promotion_id', 'fk_orders_promotions_promotion')
                  ->references('promotion_id')->on('promotions')
                  ->onDelete('set null');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('orders_promotions');
    }
};
