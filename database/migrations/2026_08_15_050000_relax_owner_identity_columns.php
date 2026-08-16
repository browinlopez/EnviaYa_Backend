<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * La papelería del propietario deja de ser obligatoria en la base.
 *
 * `document_type_id` y `document_number` estaban como NOT NULL sin valor por
 * defecto, así que dar de alta un propietario desde el panel sin tener su
 * cédula a mano reventaba con un 1048 en vez de guardarse. Son datos que se
 * completan después, igual que la fecha de nacimiento, que ya era nula.
 *
 * `notes` era varchar(45): cualquier observación real se truncaba (mismo
 * problema que tuvo `business.logo`). Pasa a TEXT.
 */
return new class extends Migration
{
    /*
     * `ALTER TABLE ... MODIFY` es sintaxis de MySQL, que es lo que corre en
     * producción. Los tests usan SQLite en memoria, donde eso no existe y
     * tumbaba la suite completa, así que el resto de motores va por el schema
     * builder, que Laravel traduce a la reconstrucción de tabla que toque.
     */
    private function esMysql(): bool
    {
        return in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true);
    }

    public function up(): void
    {
        if ($this->esMysql()) {
            DB::statement('ALTER TABLE `owner` MODIFY `document_type_id` BIGINT UNSIGNED NULL');
            DB::statement('ALTER TABLE `owner` MODIFY `document_number` VARCHAR(50) NULL');
            DB::statement('ALTER TABLE `owner` MODIFY `notes` TEXT NULL');

            return;
        }

        Schema::table('owner', function (Blueprint $table) {
            $table->unsignedBigInteger('document_type_id')->nullable()->change();
            $table->string('document_number', 50)->nullable()->change();
            $table->text('notes')->nullable()->change();
        });
    }

    public function down(): void
    {
        // Volver a NOT NULL exigiría inventar un valor para las filas que
        // queden sin documento, así que solo se restaura el tamaño de `notes`.
        if ($this->esMysql()) {
            DB::statement('ALTER TABLE `owner` MODIFY `notes` VARCHAR(45) NULL');

            return;
        }

        Schema::table('owner', function (Blueprint $table) {
            $table->string('notes', 45)->nullable()->change();
        });
    }
};
