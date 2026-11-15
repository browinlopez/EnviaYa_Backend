<?php

declare(strict_types=1);

namespace App\Queries;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use stdClass;

/**
 * LAS LECTURAS DE PROPIETARIOS DEL PANEL INTERNO.
 *
 * §12 pide las consultas complejas en `Queries/` y §21 concreta el caso: un
 * listado con varias relaciones y un agregado. Esto eran dos consultas con
 * cinco uniones y un `groupBy`, más el reparto en memoria, escritas dentro de
 * `PropietariosApiController`.
 *
 * NO CAMBIA NI UNA COMA. Se mueven con sus comentarios, que es lo que las
 * hace mantenibles: los dos cuentan por qué la consulta es así y no de la
 * forma obvia.
 */
class PropietariosDelPanel
{
    /** Estado «entregado» de un pedido, para la columna de ventas. */
    public function __construct(private readonly int $entregado) {}

    /**
     * Cada propietario con sus negocios y lo que han vendido.
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
        $propietarios = DB::table('owner as o')
            ->leftJoin('user as u', 'u.user_id', '=', 'o.user_id')
            // `document_types` usa `id` como llave y guarda el rótulo en
            // `name_es` / `name_en`; no tiene columna `name`.
            ->leftJoin('document_types as dt', 'dt.id', '=', 'o.document_type_id')
            ->orderBy('u.name')
            ->get([
                'o.owner_id', 'o.user_id', 'o.document_type_id', 'o.document_number',
                'o.birthdate', 'o.contact_secondary', 'o.notes', 'o.state', 'o.profile_photo',
                'u.name', 'u.email', 'u.phone', 'u.address',
                'u.state as account_state',
                'dt.code as document_type',
            ]);

        // Los negocios se traen en una sola consulta y se reparten en
        // memoria: un join dejaría una fila por negocio y habría que
        // desduplicar el propietario.
        $negocios = DB::table('owner_busines as ob')
            ->leftJoin('business as b', 'b.busines_id', '=', 'ob.busines_id')
            ->leftJoin('orderssales as o', function ($j) {
                $j->on('o.busines_id', '=', 'b.busines_id')->where('o.state', '=', $this->entregado);
            })
            ->groupBy('ob.owner_id', 'b.busines_id', 'b.name')
            ->get([
                'ob.owner_id', 'b.busines_id', 'b.name',
                DB::raw('COALESCE(SUM(o.total), 0) as revenue'),
            ])
            ->groupBy('owner_id');

        return $propietarios->map(function ($p) use ($negocios) {
            $suyos = $negocios[$p->owner_id] ?? collect();
            $p->businesses = $suyos->values();
            $p->businesses_count = $suyos->count();
            $p->business_ids = $suyos->pluck('busines_id')->filter()->values();
            $p->revenue = round($suyos->sum('revenue'), 2);

            return $p;
        });
    }

    /** Tipos de documento y negocios libres, para los selectores de la ficha. */
    public function opcionesDeLaFicha(): array
    {
        return [
            'document_types' => DB::table('document_types')
                ->orderBy('id')
                ->get(['id', 'code', DB::raw('name_es as name')]),
            'businesses' => DB::table('business as b')
                ->leftJoin('owner_busines as ob', 'ob.busines_id', '=', 'b.busines_id')
                ->leftJoin('owner as o', 'o.owner_id', '=', 'ob.owner_id')
                ->leftJoin('user as u', 'u.user_id', '=', 'o.user_id')
                ->orderBy('b.name')
                ->get([
                    'b.busines_id', 'b.name', 'b.address', 'b.state',
                    'ob.owner_id as current_owner_id',
                    'u.name as current_owner_name',
                ]),
        ];
    }
}
