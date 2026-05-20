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
        Schema::create('webhooks', function (Blueprint $table) {
            $table->id();
            $table->string('source', 30);
            $table->string('status', 50)->nullable();
            $table->json('payload');
            $table->unsignedBigInteger('order_sale_id')->nullable();
            $table->unsignedBigInteger('payment_id')->nullable();
            $table->timestamps();

            $table->foreign('order_sale_id')
                ->references('id')->on('orders_sales')
                ->onDelete('set null');

            $table->foreign('payment_id')
                ->references('id')->on('payments')
                ->onDelete('set null');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('webhooks');
    }
};
