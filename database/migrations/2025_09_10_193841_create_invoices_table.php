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
        Schema::create('invoices', function (Blueprint $table) {
            // en tu SQL no es AUTO_INCREMENT, lo dejamos manual
            $table->integer('invoice_id')->primary();

            // campos
            $table->unsignedInteger('orderSales_id')->nullable();
            $table->string('invoice_number', 80);
            $table->dateTime('invoice_date')->nullable();
            $table->decimal('subtotal', 10, 2)->nullable();
            $table->decimal('descuento', 10, 2)->nullable();
            $table->decimal('iva', 10, 2)->nullable();
            $table->decimal('total', 10, 2)->nullable();

            // si luego quieres relacionarlo con orderSales, aquí va el FK
            // $table->foreign('orderSales_id')
            //       ->references('orderSales_id')->on('orderSales')
            //       ->onDelete('set null');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('invoices');
    }
};
