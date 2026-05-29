<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('orders_sales', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('buyer_id')->nullable();
            $table->unsignedBigInteger('business_id')->nullable();
            $table->unsignedBigInteger('domiciliary_id')->nullable();
            $table->unsignedBigInteger('address_id')->nullable();
            $table->unsignedBigInteger('methods_id')->nullable();
            $table->unsignedBigInteger('forms_id')->nullable();
            $table->decimal('total', 10, 2)->nullable();
            $table->dateTime('sale_date')->nullable();
            $table->tinyInteger('state')->nullable();

            $table->boolean('pickup')->default(false);
            $table->timestamp('pickup_time')->nullable();

            $table->timestamp('delivery_date')->nullable();
            $table->boolean('is_scheduled')->default(false);

            $table->timestamps();

            // índices + FKs
            $table->index('buyer_id', 'fk_orders_sales_buyer');
            $table->index('business_id', 'fk_orders_sales_business');
            $table->index('domiciliary_id', 'fk_orders_sales_domiciliary');
            $table->index('methods_id', 'fk_orders_sales_methods');
            $table->index('forms_id', 'fk_orders_sales_forms');

            $table->foreign('buyer_id', 'fk_orders_sales_buyer')
                ->references('id')->on('buyers')
                ->onDelete('set null');

            $table->foreign('business_id', 'fk_orders_sales_business')
                ->references('id')->on('business')
                ->onDelete('set null');

            $table->foreign('domiciliary_id', 'fk_orders_sales_domiciliary')
                ->references('id')->on('domiciliaries')
                ->onDelete('set null');

            $table->foreign('methods_id', 'fk_orders_sales_methods')
                ->references('id')->on('payment_methods')
                ->onDelete('set null');

            $table->foreign('forms_id', 'fk_orders_sales_forms')
                ->references('id')->on('payment_forms')
                ->onDelete('set null');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::disableForeignKeyConstraints();
        Schema::dropIfExists('orders_sales');
        Schema::enableForeignKeyConstraints();
    }
};
