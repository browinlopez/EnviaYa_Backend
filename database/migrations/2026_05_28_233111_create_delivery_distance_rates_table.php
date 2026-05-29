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
        Schema::create('delivery_distance_rates', function (Blueprint $table) {
            $table->id();
            $table->integer('min_distance_meters');
            $table->integer('max_distance_meters');
            $table->decimal('price_cop', 10, 2);
            $table->boolean('active')->default(true);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('delivery_distance_rates');
    }
};
