<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up()
    {
        Schema::create('products_business', function (Blueprint $table) {
            $table->increments('busines_products_id');
            $table->unsignedBigInteger('busines_id');
            $table->unsignedBigInteger('products_id');
            $table->decimal('price', 10, 2);
            $table->integer('amount')->default(0);
            $table->decimal('qualification', 3, 2)->default(0.00);
        });
    }


    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('products_business');
    }
};
