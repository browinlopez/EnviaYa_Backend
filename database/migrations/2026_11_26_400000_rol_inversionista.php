<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * EL INVERSIONISTA: UN ROL QUE SOLO MIRA CIFRAS.
 *
 * Entra al panel de aliados y ve un tablero de seguimiento de la empresa:
 * negocios activos, compradores, pedidos, ticket promedio, crecimiento por mes
 * y avance por ciudad. Solo agregados: nunca un nombre, un teléfono ni un
 * pedido concreto.
 *
 * No tiene tabla propia ni vínculo con un negocio o un conjunto: mira la
 * plataforma entera, así que no hay nada que atar. Su puerta en el servidor es
 * `EnsureInvestor`.
 *
 * `rol` NO es autoincremental: el identificador va explícito, siguiendo la
 * serie (1 comprador, 2 tendero, 3 domiciliario, 4 personal interno, 5 dueño
 * de conjunto, 6 celador).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('rol')->updateOrInsert(
            ['rol_id' => 7],
            ['name' => 'inversionista', 'guard_name' => 'web'],
        );
    }

    public function down(): void
    {
        /*
         * Una cuenta con el rol 7 se queda sin puerta si el rol desaparece, y
         * `user.rol` es clave foránea: borrar la fila con cuentas colgando
         * revienta. Se deja la fila en ese caso; las cuentas se reasignan a
         * mano primero.
         */
        if (DB::table('user')->where('rol', 7)->exists()) {
            return;
        }

        DB::table('rol')->where('rol_id', 7)->delete();
    }
};
