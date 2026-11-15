<?php

declare(strict_types=1);

namespace App\Queries;

use App\Models\Domiciliary;
use App\Models\Operacion\CashDeposit;
use Illuminate\Database\Eloquent\Collection as Eloquentes;
use Illuminate\Support\Collection;
use stdClass;

/**
 * LAS LECTURAS DEL EFECTIVO DE UNA TIENDA.
 *
 * §12 pide las consultas complejas en `Queries/`. `index()` tenía CUATRO
 * consultas con cinco uniones y un `GROUP BY` mezcladas con el armado de la
 * respuesta; aquí vienen las cuatro consultas, tal cual.
 *
 * LO QUE **NO** SE MUEVE, Y ES DELIBERADO: el mapeo a la forma de la respuesta
 * —los `->map(fn ($e) => [...])`— se queda en el controlador. Es presentación,
 * no lectura, y §21 no pide moverla; convertirla en `JsonResource` es otro
 * trabajo y además `formas_de_entidad.py` la clasifica como INFORME, donde un
 * Resource sería la ceremonia que §05 prohíbe.
 *
 * TODO FILTRADO POR LA TIENDA DE LA SESIÓN, que es lo que §12 exige de una
 * Query: «filtran en SQL por tenant/usuario». El identificador llega como
 * argumento y lo resuelve el middleware `negocio`, nunca el cliente.
 */
class EfectivoDeLaTienda
{
    /**
     * Los domiciliarios activos de esa tienda, con su usuario.
     *
     * @return Eloquentes<int, Domiciliary>
     */
    public function flota(int $negocioId): Eloquentes
    {
        return Domiciliary::with('user')
            ->whereHas('businesses', fn ($q) => $q->where('business.busines_id', $negocioId)->where('business_domiciliary.state', 1))
            ->get();
    }

    /**
     * Cuándo entregó cada uno por última vez en el mostrador.
     *
     * @return Collection<int|string, mixed>
     */
    public function ultimaEntregaPorDomiciliario(int $negocioId): Collection
    {
        return CashDeposit::where('busines_id', $negocioId)
            ->where('canal', CashDeposit::MOSTRADOR)
            ->where('state', CashDeposit::CONFIRMADA)
            ->selectRaw('domiciliary_id, MAX(confirmed_at) as ultima')
            ->groupBy('domiciliary_id')
            ->pluck('ultima', 'domiciliary_id');
    }

    /**
     * Las últimas cien entregas en el mostrador, con quién entregó y quién
     * recibió.
     *
     * `stdClass` y no `object`: los genéricos de `Collection` son INVARIANTES.
     *
     * @return Eloquentes<int, CashDeposit>
     */
    public function entregasEnMostrador(int $negocioId): Eloquentes
    {
        return CashDeposit::where('cash_deposits.busines_id', $negocioId)
            ->where('cash_deposits.canal', CashDeposit::MOSTRADOR)
            ->leftJoin('domiciliary as d', 'd.domiciliary_id', '=', 'cash_deposits.domiciliary_id')
            ->leftJoin('user as u', 'u.user_id', '=', 'd.user_id')
            ->leftJoin('user as r', 'r.user_id', '=', 'cash_deposits.confirmed_by')
            ->latest('cash_deposits.confirmed_at')
            ->limit(100)
            ->get([
                'cash_deposits.id', 'cash_deposits.domiciliary_id', 'cash_deposits.amount',
                'cash_deposits.notes', 'cash_deposits.confirmed_at', 'cash_deposits.settlement_id',
                'u.name as domiciliario', 'r.name as recibio',
            ]);
    }

    /**
     * Las últimas cincuenta consignaciones de esa flota.
     *
     * Con la flota vacía NO se consulta: un `whereIn` sin valores devuelve
     * cero filas pero gasta la consulta igual.
     *
     * @param  list<int>  $ids
     * @return Eloquentes<int, CashDeposit>|Collection<int, stdClass>
     */
    public function consignaciones(array $ids): Eloquentes|Collection
    {
        if ($ids === []) {
            return collect();
        }

        return CashDeposit::whereIn('cash_deposits.domiciliary_id', $ids)
            ->where('cash_deposits.canal', CashDeposit::CONSIGNACION)
            ->leftJoin('domiciliary as d', 'd.domiciliary_id', '=', 'cash_deposits.domiciliary_id')
            ->leftJoin('user as u', 'u.user_id', '=', 'd.user_id')
            ->latest('cash_deposits.created_at')
            ->limit(50)
            ->get(['cash_deposits.*', 'u.name as domiciliario']);
    }
}
