<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_intents', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('order_sale_id');
            $table->string('provider', 30)->default('bold');
            $table->string('bold_reference_id')->unique();
            $table->decimal('amount', 10, 2);
            $table->string('currency', 3)->default('COP');
            $table->string('status', 30);
            $table->json('payload')->nullable();
            $table->json('response')->nullable();
            $table->timestamps();

            $table->foreign('order_sale_id')
                  ->references('id')->on('orders_sales')
                  ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_intents');
    }
};
