<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('category_business', function (Blueprint $table) {
            $table->id(); // id principal
            $table->string('name'); // nombre de la categoría
            $table->text('description')->nullable(); // descripción opcional
            $table->string('image')->nullable(); // ruta o nombre del archivo de imagen
            $table->timestamps();
        });

        // Alteramos la tabla business para añadir FK (si 'type' es FK)
        Schema::table('business', function (Blueprint $table) {
            // suponiendo que 'type' es el campo que referencia a category_business.id
            $table->unsignedBigInteger('type')->nullable()->change();

            $table->foreign('type')
                ->references('id')
                ->on('category_business')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('business', function (Blueprint $table) {
            $table->dropForeign(['type']);
        });

        Schema::dropIfExists('category_business');
    }
};
