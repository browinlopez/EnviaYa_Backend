<?php

declare(strict_types=1);

namespace App\Queries;

use App\Models\Conjunto\ComplexStaff;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use stdClass;

/**
 * EL LISTADO DE CONJUNTOS DEL PANEL INTERNO.
 *
 * §12 pide las consultas complejas en `Queries/` y esto era el caso más claro
 * de los nueve candidatos: TRES consultas que se cruzan en memoria, un
 * `GROUP BY` de veinte columnas y una transformación encima, todo dentro de
 * `ConjuntosApiController::complexes()`.
 *
 * NO CAMBIA NI UNA COMA. Se mueve con sus cuatro comentarios, y los cuatro
 * cuentan un error ya cometido: por qué el COUNT necesita alias, por qué las
 * columnas van también en el GROUP BY con `ONLY_FULL_GROUP_BY`, por qué el
 * administrador se cruza en memoria y no con un cuarto join, y qué pasa
 * cuando un conjunto no tiene a nadie.
 */
class ConjuntosDelPanel
{
    /**
     * Cada conjunto con su municipio, sus residentes, su administrador y
     * cuántos celadores tiene.
     *
     * `stdClass` y no `object`: los genericos de `Collection` son
     * INVARIANTES, asi que `Collection<int, object>` NO acepta un
     * `Collection<int, stdClass>` aunque un stdClass sea un object. Es la
     * misma trampa que `TorreDeControlController::ultimaPosicion()`.
     *
     * @return Collection<int, stdClass>
     */
    public function listado(): Collection
    {
        /*
         * El administrador de cada conjunto y cuantos celadores tiene, en dos
         * consultas planas que se cruzan en memoria. `complex_staff` es la
         * tabla que dice CUAL conjunto es de quien: sin fila ahi, una cuenta
         * con rol 5 entra al panel de aliados y no ve absolutamente nada.
         */
        $duenos = DB::table('complex_staff as cs')
            ->join('user as u', 'u.user_id', '=', 'cs.user_id')
            ->where('cs.role', ComplexStaff::DUENO)
            ->get(['cs.complex_id', 'u.user_id', 'u.name', 'u.email', 'u.state'])
            ->keyBy('complex_id');

        $celadores = DB::table('complex_staff')
            ->where('role', ComplexStaff::CELADOR)
            ->where('state', true)
            ->groupBy('complex_id')
            // El COUNT necesita un alias propio: `pluck` pide la columna por
            // nombre y una expresión cruda no lo tiene, así que devolvía cero
            // para todos los conjuntos.
            ->selectRaw('complex_id, COUNT(*) as total')
            ->pluck('total', 'complex_id');

        return DB::table('residential_complexes as rc')
            ->leftJoin('buyer_complex as bc', 'bc.complex_id', '=', 'rc.complex_id')
            /*
             * La ficha del conjunto viaja con la lista: el formulario del
             * panel la abre desde aca y sin estos campos habria que pedir
             * cada conjunto aparte solo para editarlo.
             *
             * Van tambien en el GROUP BY porque la consulta agrupa para
             * contar residentes, y en MySQL con ONLY_FULL_GROUP_BY una
             * columna seleccionada y no agrupada tumba la consulta.
             */
            ->groupBy('rc.complex_id', 'rc.name', 'rc.address', 'rc.state', 'rc.people_count',
                'rc.latitude', 'rc.longitude', 'rc.municipality_id',
                'rc.towers_count', 'rc.apartments_per_tower',
                'rc.photo', 'rc.phone', 'rc.email', 'rc.admin_name', 'rc.nit',
                'rc.gate_notes', 'rc.require_authorization',
                'm.name', 'dp.id', 'dp.name')
            ->orderBy('rc.name')
            ->leftJoin('municipalities as m', 'm.id', '=', 'rc.municipality_id')
            ->leftJoin('departments as dp', 'dp.id', '=', 'm.department_id')
            ->get([
                'rc.complex_id', 'rc.name', 'rc.address', 'rc.state', 'rc.people_count',
                'rc.latitude', 'rc.longitude', 'rc.municipality_id',
                'rc.towers_count', 'rc.apartments_per_tower',
                'rc.photo', 'rc.phone', 'rc.email', 'rc.admin_name', 'rc.nit',
                'rc.gate_notes', 'rc.require_authorization',
                'm.name as municipality_name', 'dp.id as department_id', 'dp.name as department_name',
                DB::raw('COUNT(bc.buyer_id) as residents_count'),
            ])
            /*
             * QUIEN ADMINISTRA CADA CONJUNTO VIAJA CON LA LISTA.
             *
             * Sin esto el panel no puede decir "este conjunto no tiene a
             * nadie", que es exactamente el estado en el que nace y el que
             * hay que ver de un vistazo. Un conjunto sin administrador se
             * ve idéntico a uno con administrador, y nadie lo nota hasta
             * que el dueño llama diciendo que no puede entrar.
             *
             * Va como transformación en memoria y no como otro join: la
             * consulta ya agrupa para contar residentes y meterle una
             * segunda tabla obligaría a añadir sus columnas al GROUP BY.
             */
            ->map(function ($c) use ($duenos, $celadores) {
                $d = $duenos[$c->complex_id] ?? null;

                $c->admin_user_id = $d->user_id ?? null;
                $c->admin_email = $d->email ?? null;
                $c->admin_account = $d->name ?? null;
                $c->admin_active = $d ? (bool) $d->state : null;
                $c->guards_count = $celadores[$c->complex_id] ?? 0;

                return $c;
            });
    }
}
