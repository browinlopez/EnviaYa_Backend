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
        Schema::create('order_promotions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('order_sales_id');
            $table->unsignedBigInteger('promotion_id')->nullable();
            $table->tinyInteger('state')->nullable();

            $table->foreign('order_sales_id')
                  ->references('id')->on('orders_sales')
                  ->onDelete('cascade');

            $table->foreign('promotion_id')
                  ->references('id')->on('promotions')
                  ->onDelete('set null');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('order_promotions');
    }
};
