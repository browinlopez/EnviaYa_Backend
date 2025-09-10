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
        Schema::create('orderspurchase', function (Blueprint $table) {
            $table->increments('orderPursh_id');
            $table->unsignedBigInteger('supplier_id')->nullable(); // FK a suppliers
            $table->unsignedInteger('methods_id')->nullable();
            $table->unsignedInteger('forms_id')->nullable();
            $table->dateTime('purchase_date')->nullable();
            $table->decimal('total', 10, 2)->nullable();

            // index + FK
            $table->index('supplier_id', 'fk_orderspurchase_supplier');
            $table->foreign('supplier_id', 'fk_orderspurchase_supplier')
                ->references('supplier_id')->on('suppliers')
                ->onDelete('set null');
            $table->index('methods_id', 'fk_orderspurchase_methods');
            $table->index('forms_id', 'fk_orderspurchase_forms');

            $table->foreign('methods_id', 'fk_orderspurchase_methods')
                ->references('methods_id')->on('payment_methods') // ✅ tabla real
                ->onDelete('set null');

            $table->foreign('forms_id', 'fk_orderspurchase_forms')
                ->references('forms_id')->on('payment_forms')
                ->onDelete('set null');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('orderspurchase');
    }
};
