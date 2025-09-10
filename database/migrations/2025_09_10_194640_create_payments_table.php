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
            $table->increments('payments_id');
            $table->unsignedInteger('orderSales_id')->nullable(); // FK a orderssales
            $table->unsignedInteger('methods_id')->nullable(); // FK a payment_methods
            $table->unsignedInteger('forms_id')->nullable(); // FK a payment_forms
            $table->decimal('amount', 10, 2);
            $table->integer('payment_status');
            $table->timestamp('payment_date')->nullable();
            $table->tinyInteger('state')->nullable();

            $table->index('orderSales_id', 'fk_payments_orderSales');
            $table->index('methods_id', 'fk_payments_methods');
            $table->index('forms_id', 'fk_payments_forms');

            $table->foreign('orderSales_id', 'fk_payments_orderSales')
                ->references('orderSales_id')->on('orderssales')
                ->onDelete('set null');

            $table->foreign('methods_id', 'fk_payments_methods')
                ->references('methods_id')->on('payment_methods')
                ->onDelete('set null');

            $table->foreign('forms_id', 'fk_payments_forms')
                ->references('forms_id')->on('payment_forms')
                ->onDelete('set null');
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
