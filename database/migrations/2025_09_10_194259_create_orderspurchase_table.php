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
        Schema::create('orders_purchases', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('supplier_id')->nullable(); // FK a suppliers
            $table->unsignedInteger('methods_id')->nullable();
            $table->unsignedInteger('forms_id')->nullable();
            $table->dateTime('purchase_date')->nullable();
            $table->decimal('total', 10, 2)->nullable();

            $table->foreign('supplier_id')
                ->references('id')->on('suppliers')
                ->onDelete('set null');

            $table->foreign('methods_id')
                ->references('id')->on('payment_methods')
                ->onDelete('set null');

            $table->foreign('forms_id')
                ->references('id')->on('payment_forms')
                ->onDelete('set null');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('orders_purchases');
    }
};
