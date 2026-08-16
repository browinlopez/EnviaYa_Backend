<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Áreas de la empresa: la capa de permisos del panel.
 *
 * NO reemplaza a `rol`. Esa tabla describe qué es alguien para la APP MÓVIL
 * —comprador, tendero, domiciliario— y la app publicada depende de ella; el
 * rol 4 pasa a significar simplemente "es personal y entra al panel". El área
 * responde otra pregunta: de las 24 secciones del panel, cuáles le tocan.
 *
 * Mezclarlas en `rol` habría obligado a que un contador fuera "rol 6" y a que
 * la app móvil tuviera que aprender roles que no le importan.
 *
 * `is_system` protege al área de Tecnología: es la única con acceso a todo, y
 * si alguien pudiera quitarle módulos o borrarla, un descuido dejaría el panel
 * sin nadie capaz de repartir permisos. El servidor la trata como intocable.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('areas', function (Blueprint $table) {
            $table->id();

            // Estable y legible: es lo que usan las semillas y el código, para
            // no depender de un id autoincremental que cambia entre entornos.
            $table->string('code', 30)->unique();

            $table->string('name', 80);
            $table->string('description', 255)->nullable();

            $table->boolean('is_system')->default(false);
            $table->tinyInteger('state')->default(1);

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('areas');
    }
};
