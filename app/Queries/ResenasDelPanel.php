<?php

declare(strict_types=1);

namespace App\Queries;

use App\Support\ListadoPaginado;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * LAS LECTURAS DE RESEÑAS DEL PANEL INTERNO.
 *
 * §12 pide las consultas complejas en `Queries/` y esta era la de forma más
 * difícil de los nueve candidatos: UNA consulta polimórfica sobre TRES tablas
 * —`business_reviews`, `domiciliary_reviews`, `user_reviews`— que no tienen la
 * misma forma, con cuatro uniones que cambian según el ámbito, tres filtros y
 * un bloque de indicadores. Vivía dentro de `ResenasApiController`.
 *
 * EL MAPA ES PÚBLICO a propósito: lo usan también los dos métodos de borrado
 * del controlador para saber en qué tabla escribir. Tenerlo privado allí era
 * lo que ataba las tres cosas al mismo fichero.
 *
 * RECIBE LOS FILTROS, NO LA PETICIÓN, por lo mismo que las demás: pasar el
 * `Request` meteria HTTP en la capa de datos.
 */
class ResenasDelPanel
{
    /** Las tres tablas de reseñas tienen forma distinta; se unifican acá. */
    public function mapa(string $scope): array
    {
        return match ($scope) {
            'business' => [
                'table' => 'business_reviews',
                'target' => ['business', 'busines_id', 'name'],
                'target_key' => 'busines_id',
                'author_key' => 'buyer_id',
                'author_via_buyer' => true,
                'has_dates' => true,
            ],
            'domiciliary' => [
                'table' => 'domiciliary_reviews',
                'target' => ['domiciliary', 'domiciliary_id', null],
                'target_key' => 'domiciliary_id',
                'author_key' => 'buyer_id',
                'author_via_buyer' => true,
                'has_dates' => true,
            ],
            'user' => [
                'table' => 'user_reviews',
                'target' => ['user', 'user_id', 'name'],
                'target_key' => 'user_id',
                'author_key' => 'domiciliary_id',
                'author_via_buyer' => false,
                'has_dates' => false,
            ],
            default => throw new InvalidArgumentException('Ámbito de reseña no válido.'),
        };
    }

    /**
     * Las reseñas de un ámbito, con su destino, su autor y los filtros.
     *
     * @param  string|null  $puntaje  `negativas`, `positivas` o `comentadas`
     */
    public function listado(string $scope, ?int $negocio = null, ?string $puntaje = null): Builder
    {
        $m = $this->mapa($scope);

        $q = DB::table($m['table'].' as r');
        $cols = ['r.reviews_id', 'r.qualification', 'r.comment', 'r.state'];

        if ($m['has_dates']) {
            $cols[] = 'r.created_at';
        }

        // Destino de la reseña
        if ($scope === 'domiciliary') {
            $q->leftJoin('domiciliary as t', 't.domiciliary_id', '=', 'r.domiciliary_id')
                ->leftJoin('user as tu', 'tu.user_id', '=', 't.user_id');
            $cols[] = DB::raw('r.domiciliary_id as target_id');
            $cols[] = DB::raw('tu.name as target_name');
        } else {
            [$tabla, $llave, $campoNombre] = $m['target'];
            $q->leftJoin("{$tabla} as t", "t.{$llave}", '=', "r.{$m['target_key']}");
            $cols[] = DB::raw("r.{$m['target_key']} as target_id");
            $cols[] = DB::raw("t.{$campoNombre} as target_name");
        }

        // Autor de la reseña
        if ($m['author_via_buyer']) {
            $q->leftJoin('buyer as ab', 'ab.buyer_id', '=', 'r.buyer_id')
                ->leftJoin('user as au', 'au.user_id', '=', 'ab.user_id');
        } else {
            $q->leftJoin('domiciliary as ad', 'ad.domiciliary_id', '=', 'r.domiciliary_id')
                ->leftJoin('user as au', 'au.user_id', '=', 'ad.user_id');
        }
        $cols[] = DB::raw('au.name as author_name');

        $q->select($cols);

        /*
         * Negocio.
         *
         * Solo tiene sentido en el ámbito de negocios: en el de domiciliarios
         * el destino de la reseña es una persona, no una tienda. Aplicarlo ahí
         * devolvería vacío sin explicar por qué, así que simplemente se ignora.
         */
        if ($scope !== 'domiciliary' && $negocio) {
            $q->where("r.{$m['target_key']}", $negocio);
        }

        // Puntaje: negativas (1-2), positivas (4-5) o con comentario.
        match ($puntaje) {
            'negativas' => $q->whereBetween('r.qualification', [1, 2]),
            'positivas' => $q->where('r.qualification', '>=', 4),
            'comentadas' => $q->whereNotNull('r.comment')->where('r.comment', '!=', ''),
            default => null,
        };

        return $q;
    }

    /** Por qué columnas se puede ordenar, que depende del ámbito. */
    public function ordenables(string $scope): array
    {
        $ordenables = [
            'reviews_id' => 'r.reviews_id',
            'qualification' => 'r.qualification',
            'target_name' => 't.name',
        ];

        if ($this->mapa($scope)['has_dates']) {
            $ordenables['created_at'] = 'r.created_at';
        }

        return $ordenables;
    }

    /** Indicadores sobre todo lo filtrado. */
    public function resumen($q): array
    {
        $r = ListadoPaginado::soloAgregados($q, "
            COUNT(*) as total,
            AVG(r.qualification) as promedio,
            SUM(CASE WHEN r.qualification BETWEEN 1 AND 2 THEN 1 ELSE 0 END) as negativas,
            SUM(CASE WHEN r.comment IS NOT NULL AND r.comment <> '' THEN 1 ELSE 0 END) as comentadas
        ");

        return [
            'total' => (int) ($r->total ?? 0),
            // Sin reseñas el promedio no es 0 estrellas, es que no hay: un 0,0
            // se lee como "todo el mundo la odia".
            'promedio' => $r && $r->total > 0 ? round((float) $r->promedio, 2) : null,
            'negativas' => (int) ($r->negativas ?? 0),
            'comentadas' => (int) ($r->comentadas ?? 0),
        ];
    }
}
