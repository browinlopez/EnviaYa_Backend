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
        Schema::create('order_geolocations', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('domiciliary_id');
            $table->decimal('latitude', 9, 6)->nullable();
            $table->decimal('length', 9, 6)->nullable();
            $table->tinyInteger('state')->nullable();

            $table->foreign('domiciliary_id')
                  ->references('id')->on('domiciliaries')
                  ->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('order_geolocations');
    }
};
