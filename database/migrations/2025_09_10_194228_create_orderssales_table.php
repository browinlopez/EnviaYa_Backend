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
        Schema::create('orderssales', function (Blueprint $table) {
            $table->increments('orderSales_id');
            $table->unsignedInteger('buyer_id')->nullable();
            $table->unsignedInteger('busines_id')->nullable();
            $table->unsignedInteger('domiciliary_id')->nullable();
            $table->string('delivery_address', 255);
            $table->unsignedInteger('methods_id')->nullable();
            $table->unsignedInteger('forms_id')->nullable();
            $table->decimal('total', 10, 2)->nullable();
            $table->dateTime('sale_date')->nullable();
            $table->tinyInteger('state')->nullable();

            // índices + FKs
            $table->index('buyer_id', 'fk_orderssales_buyer');
            $table->index('busines_id', 'fk_orderssales_business');
            $table->index('domiciliary_id', 'fk_orderssales_domiciliary');
            $table->index('methods_id', 'fk_orderssales_methods');
            $table->index('forms_id', 'fk_orderssales_forms');

            $table->foreign('buyer_id', 'fk_orderssales_buyer')
                ->references('buyer_id')->on('buyer')
                ->onDelete('set null');

            $table->foreign('busines_id', 'fk_orderssales_business')
                ->references('busines_id')->on('business')
                ->onDelete('set null');

            $table->foreign('domiciliary_id', 'fk_orderssales_domiciliary')
                ->references('domiciliary_id')->on('domiciliary')
                ->onDelete('set null');

            $table->foreign('methods_id', 'fk_orderssales_methods')
                ->references('methods_id')->on('payment_methods') // ✅ tabla real
                ->onDelete('set null');

            $table->foreign('forms_id', 'fk_orderssales_forms')
                ->references('forms_id')->on('payment_forms')
                ->onDelete('set null');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('orderssales');
    }
};
