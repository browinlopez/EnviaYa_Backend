<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

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
    public function up(): void
    {
        DB::statement('ALTER TABLE `owner` MODIFY `document_type_id` BIGINT UNSIGNED NULL');
        DB::statement('ALTER TABLE `owner` MODIFY `document_number` VARCHAR(50) NULL');
        DB::statement('ALTER TABLE `owner` MODIFY `notes` TEXT NULL');
    }

    public function down(): void
    {
        // Volver a NOT NULL exigiría inventar un valor para las filas que
        // queden sin documento, así que solo se restaura el tamaño de `notes`.
        DB::statement('ALTER TABLE `owner` MODIFY `notes` VARCHAR(45) NULL');
    }
};
