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
        Schema::create('payments', function (Blueprint $table) {
            $table->id(); // PK id
            $table->unsignedBigInteger('order_sale_id');
            $table->unsignedBigInteger('methods_id');
            $table->unsignedBigInteger('forms_id')->nullable();
            $table->decimal('amount', 10, 2);
            $table->decimal('subtotal', 10, 2)->nullable();
            $table->decimal('total', 10, 2)->nullable();
            $table->tinyInteger('payment_status')->default(0);
            $table->timestamp('payment_date')->nullable();
            $table->tinyInteger('state')->default(1);
            $table->timestamps();

            $table->foreign('order_sale_id')
                ->references('id')->on('orders_sales')
                ->onDelete('cascade');

            $table->foreign('methods_id', 'fk_payments_methods')
                ->references('id')->on('payment_methods')
                ->onDelete('cascade');

            $table->foreign('forms_id', 'fk_payments_forms')
                ->references('id')->on('payment_forms')
                ->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};
