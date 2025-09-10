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
        Schema::create('order_geolocation', function (Blueprint $table) {
            $table->increments('geolocation_id'); // PK
            $table->unsignedInteger('domiciliary_id'); // FK hacia domiciliary
            $table->decimal('latitude', 9, 6)->nullable();
            $table->decimal('length', 9, 6)->nullable();
            $table->tinyInteger('state')->nullable();

            // Índice
            $table->index('domiciliary_id', 'fk_order_geolocation_domiciliary');

            // Foreign Key
            $table->foreign('domiciliary_id', 'fk_order_geolocation_domiciliary')
                  ->references('domiciliary_id')->on('domiciliary')
                  ->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('order_geolocation');
    }
};
