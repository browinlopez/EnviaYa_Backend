<?php

declare(strict_types=1);

namespace App\Queries;

use App\Models\Operacion\CashDeposit;
use App\Models\Operacion\CashMovement;
use Illuminate\Database\Eloquent\Collection as Eloquentes;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use stdClass;

/**
 * LAS LECTURAS DEL EFECTIVO DE LA OPERACIÓN.
 *
 * §12 pide las consultas complejas en `Queries/`. Esto eran dos consultas con
 * cuatro uniones, un `GROUP BY`, un `HAVING` crudo y un orden por agregado,
 * dentro de `Operacion\EfectivoController`.
 *
 * RECIBE LOS FILTROS, NO LA PETICIÓN, igual que las demás.
 */
class EfectivoDeLaOperacion
{
    /**
     * Los depósitos declarados, con el nombre de quien los declaró.
     *
     * El tope de 200 va aquí y no en el controlador porque es parte de la
     * consulta: sin él, la pantalla descarga la tabla entera el día que
     * crezca.
     *
     * @return Collection<int, CashDeposit>
     */
    public function depositos(?string $estado = null, ?int $domiciliario = null): Collection
    {
        $q = CashDeposit::query()
            ->leftJoin('domiciliary as d', 'd.domiciliary_id', '=', 'cash_deposits.domiciliary_id')
            ->leftJoin('user as u', 'u.user_id', '=', 'd.user_id')
            ->select([
                'cash_deposits.*',
                'u.name as domiciliary_name',
            ]);

        if ($estado) {
            $q->where('cash_deposits.state', $estado);
        }

        if ($domiciliario) {
            $q->where('cash_deposits.domiciliary_id', $domiciliario);
        }

        return $q->latest('cash_deposits.created_at')->limit(200)->get();
    }

    /**
     * Quién debe cuánto, ahora mismo. Es la pregunta que antes no se podía
     * hacer.
     *
     * El `HAVING` deja fuera a quien está a cero: la pantalla es de deudas, y
     * una lista con cuarenta ceros es una lista que nadie lee.
     *
     * LA DECLARACION DICE `CashMovement` Y NO `stdClass`, y lo corrigio
     * PHPStan: la consulta arranca en `CashMovement::query()`, asi que lo que
     * vuelve es una coleccion de ESE modelo con columnas extra --`name` y
     * `balance`-- aunque el `get()` solo pida tres campos. Escribi `stdClass`
     * por inercia de las otras Queries, que si usan `DB::table()`.
     *
     * Los genericos de `Collection` son INVARIANTES, asi que la declaracion
     * tiene que decir exactamente lo que vuelve.
     *
     * @return Eloquentes<int, CashMovement>
     */
    public function saldos(): Eloquentes
    {
        return CashMovement::query()
            ->join('domiciliary as d', 'd.domiciliary_id', '=', 'cash_movements.domiciliary_id')
            ->leftJoin('user as u', 'u.user_id', '=', 'd.user_id')
            ->groupBy('cash_movements.domiciliary_id', 'u.name')
            ->havingRaw('SUM(cash_movements.amount) <> 0')
            ->orderByRaw('SUM(cash_movements.amount) DESC')
            ->get([
                'cash_movements.domiciliary_id',
                'u.name',
                DB::raw('SUM(cash_movements.amount) as balance'),
            ]);
    }
}
