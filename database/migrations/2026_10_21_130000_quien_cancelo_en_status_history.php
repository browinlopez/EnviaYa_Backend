<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * CANCELAR UN PEDIDO REVIENTA EN PRODUCCIÓN, Y ESTO ES POR QUÉ.
 *
 * `status_history.created_by` y `updated_by` se declararon en 2025 como
 * `timestamp` —`$table->timestamp('created_by')`—, pero lo que el código
 * guarda ahí es el identificador de quien hizo el cambio. Un número pequeño
 * no es una fecha válida, y la base de producción corre con
 * `STRICT_TRANS_TABLES`:
 *
 *     SQLSTATE[22007]: Invalid datetime format: 1292
 *     Incorrect datetime value: '7' for column 'created_by'
 *
 * Y ese INSERT va DENTRO de la transacción de la cancelación, así que al
 * fallar se deshace todo: el estado no cambia, el inventario no vuelve, el
 * cupo de fiado no se libera y el comprador recibe un error. **El pedido
 * sigue vivo.** `status_history` tiene cero filas en producción, y los dos
 * pedidos cancelados que hay son de antes de que el reporte existiera.
 *
 * POR QUÉ NO LO VIO NINGUNA PRUEBA. La suite corre sobre SQLite, y SQLite
 * acepta cualquier cosa en una columna de fecha: hay una prueba que comprueba
 * que el reporte queda con el identificador correcto, y pasa. Es exactamente
 * la clase de error que no se ve hasta que alguien cancela de verdad, y por eso
 * la prueba que acompaña a esta migración no mira el valor sino el TIPO de la
 * columna, que es lo único que SQLite y MySQL cuentan igual.
 *
 * Se cambia el tipo en vez de renombrar las columnas: `created_by` ya
 * significa «quién» en el resto del proyecto —`weighed_by`,
 * `store_credit_movements.created_by`—, y lo que estaba mal era el tipo.
 *
 * Sin filas que convertir, el cambio no puede perder nada.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('status_history', function (Blueprint $table) {
            $table->unsignedBigInteger('created_by')->nullable()->change();
            $table->unsignedBigInteger('updated_by')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('status_history', function (Blueprint $table) {
            $table->timestamp('created_by')->nullable()->change();
            $table->timestamp('updated_by')->nullable()->change();
        });
    }
};
