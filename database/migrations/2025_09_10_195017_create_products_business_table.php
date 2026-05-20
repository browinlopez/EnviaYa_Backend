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
        Schema::create('product_businesses', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('busines_id');
            $table->unsignedBigInteger('products_id');
            $table->decimal('price', 10, 2);
            $table->integer('quantity')->default(0);
            $table->decimal('qualification', 3, 2)->default(0.00);
            $table->timestamps();

            $table->foreign('busines_id')->references('id')->on('business')->onDelete('cascade');
            $table->foreign('products_id')->references('id')->on('products')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('product_businesses');
    }
};
