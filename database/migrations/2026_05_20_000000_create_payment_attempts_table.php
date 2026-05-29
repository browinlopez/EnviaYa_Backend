<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_attempts', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('order_sale_id');
            $table->string('reference_id');
            $table->string('transaction_id')->nullable();
            $table->unsignedBigInteger('payment_method_id')->nullable();
            $table->unsignedBigInteger('payment_form_id')->nullable();
            $table->string('status', 30);
            $table->json('request_payload')->nullable();
            $table->json('response_payload')->nullable();
            $table->json('error_payload')->nullable();
            $table->timestamps();

            $table->foreign('order_sale_id')
                  ->references('id')->on('orders_sales')
                  ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_attempts');
    }
};
