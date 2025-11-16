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
        // Tabla principal
        Schema::create('category', function (Blueprint $table) {
            $table->increments('category_id');                        // PK autoincremental
            $table->string('name', 255);                              // Nombre
            $table->text('description')->nullable();                  // Descripción opcional
            $table->tinyInteger('state')->nullable();                 // Estado (tinyint)
        });

        // Modificación: agregar business_category_id
        Schema::table('category', function (Blueprint $table) {
            $table->unsignedBigInteger('business_category_id')->nullable();

            $table->foreign('business_category_id')
                ->references('id')
                ->on('category_business')
                ->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('category', function (Blueprint $table) {
            $table->dropForeign(['business_category_id']);
            $table->dropColumn('business_category_id');
        });

        Schema::dropIfExists('category');
    }
};