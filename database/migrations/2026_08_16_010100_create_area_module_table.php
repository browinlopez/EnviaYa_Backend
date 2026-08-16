<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Qué módulos toca cada área, y con qué alcance.
 *
 * Va en tabla y no en una columna JSON dentro de `areas` porque esto se
 * consulta en cada petición del panel: el middleware pregunta "¿esta área
 * puede tocar este módulo?", que con una fila indexada es una búsqueda directa
 * y con JSON obliga a leer y recorrer el documento entero.
 *
 * `can_manage` implica `can_view`: no tiene sentido editar algo que no se ve.
 * La regla se aplica al guardar, no acá, porque un CHECK no es portable entre
 * MySQL y el SQLite de las pruebas.
 *
 * Una fila AUSENTE significa "sin acceso". Se guarda solo lo concedido en vez
 * de una fila por módulo con banderas en false: así la tabla dice de un vistazo
 * qué puede hacer un área, en vez de obligar a leer 24 filas para descubrir que
 * casi todas son negativas.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('area_module', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('area_id');

            // La clave del catálogo (App\Support\PanelModules), no un id: es
            // legible al mirar la tabla y no exige otra tabla de referencia
            // para algo que vive en el código y no lo edita el usuario.
            $table->string('module', 40);

            $table->boolean('can_view')->default(true);
            $table->boolean('can_manage')->default(false);

            $table->timestamps();

            $table->unique(['area_id', 'module'], 'area_modulo_unico');
            $table->index('module');

            $table->foreign('area_id')
                ->references('id')->on('areas')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('area_module');
    }
};
