<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * DOS ÍNDICES QUE OTRO YA CUBRE.
 *
 * Un índice compuesto sirve también para consultar por su PREFIJO IZQUIERDO:
 * `(busines_id, state)` responde igual de bien a «dame lo de este negocio» que
 * un índice suelto sobre `busines_id`. Tenerlos los dos no acelera nada y
 * cuesta en cada escritura, porque hay que mantener las dos estructuras.
 *
 *   products_business.ix_pb_negocio        (busines_id)
 *      ya lo cubre  uq_producto_por_negocio (busines_id, products_id)
 *
 *   orderssales.fk_orderssales_business    (busines_id)
 *      ya lo cubre  ix_pedidos_negocio_estado (busines_id, state)
 *
 * El segundo lo dejé yo al añadir los índices del tablero: MySQL había creado
 * ese índice solo, para poder comprobar la foránea, y el compuesto nuevo lo
 * dejó de sobra sin que nadie lo retirara.
 *
 * SE PUEDE QUITAR CON LA FORÁNEA PUESTA. MySQL exige que exista ALGÚN índice
 * que empiece por la columna de la foránea, no ese en concreto: mientras
 * `ix_pedidos_negocio_estado` empiece por `busines_id`, la restricción sigue
 * comprobándose. Si no hubiera ninguno, el `DROP INDEX` fallaría — que es la
 * garantía de que esto no puede dejar una foránea sin cubrir.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            // En SQLite estos índices no existen: los crea MySQL por su cuenta
            // al declarar la foránea.
            return;
        }

        $this->quitarSiEsta('products_business', 'ix_pb_negocio');
        $this->quitarSiEsta('orderssales', 'fk_orderssales_business');
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        Schema::table('products_business', function (Blueprint $t) {
            $t->index('busines_id', 'ix_pb_negocio');
        });

        Schema::table('orderssales', function (Blueprint $t) {
            $t->index('busines_id', 'fk_orderssales_business');
        });
    }

    /**
     * Sin `dropIndex` a secas: si el índice ya no está —porque la migración se
     * corrió a medias, o porque alguien lo quitó a mano— reventaría, y esto no
     * es lo bastante importante como para dejar un despliegue parado.
     */
    private function quitarSiEsta(string $tabla, string $indice): void
    {
        $existe = DB::selectOne(
            'SELECT 1 x FROM information_schema.STATISTICS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?
              LIMIT 1',
            [$tabla, $indice],
        );

        if ($existe) {
            DB::statement("ALTER TABLE `{$tabla}` DROP INDEX `{$indice}`");
        }
    }
};
