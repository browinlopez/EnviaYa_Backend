<?php

use App\Support\AreasSemilla;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Crea las siete áreas con su reparto de módulos.
 *
 * Va como migración y no solo como seeder porque de esto depende poder entrar
 * al panel: si en producción nadie corre las semillas, el middleware empezaría
 * a exigir un área que no existe y dejaría a todo el mundo fuera. `migrate` es
 * lo único que sí se ejecuta siempre en un despliegue.
 *
 * La migración siguiente asigna Tecnología a los administradores que ya había,
 * y por eso esta tiene que correr antes.
 */
return new class extends Migration
{
    public function up(): void
    {
        AreasSemilla::sembrar();
    }

    public function down(): void
    {
        $codigos = array_keys(AreasSemilla::definiciones());

        // Se borran solo las de fábrica: las que alguien haya creado desde el
        // panel no son de esta migración y no le corresponde llevárselas.
        DB::table('areas')->whereIn('code', $codigos)->delete();
    }
};
