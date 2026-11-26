<?php

declare(strict_types=1);

namespace App\Queries;

use App\Support\Like;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use stdClass;

/**
 * LAS LECTURAS DE RESIDENTES DE UN CONJUNTO.
 *
 * §12 pide las consultas complejas en `Queries/`. Esto eran tres consultas con
 * cinco uniones —una de ellas con condición extra en el `ON`—, dos filtros
 * opcionales, dos `orderByRaw` y un recorrido del libro de entradas, dentro de
 * `Conjunto\ResidentesController`.
 *
 * TODO FILTRADO POR EL CONJUNTO DE LA SESIÓN, que es lo que §12 exige de una
 * Query: «filtran en SQL por tenant/usuario». El `complex_id` llega como
 * argumento y lo resuelve el middleware `conjunto`, nunca el cliente.
 */
class ResidentesDelConjunto
{
    /**
     * El detalle paginable: cada residente con su torre y su apartamento.
     *
     * `sin` como torre es la de quienes se registraron antes de que el campo
     * existiera: no es un valor, es su ausencia.
     *
     * `stdClass` y no `object`: los genéricos de `Collection` son INVARIANTES.
     *
     * @return Collection<int, stdClass>
     */
    public function detalle(int $complexId, ?string $torre = null, ?string $buscar = null): Collection
    {
        $consulta = DB::table('buyer_complex as bc')
            ->where('bc.complex_id', $complexId)
            ->join('buyer as b', 'b.buyer_id', '=', 'bc.buyer_id')
            ->join('user as u', 'u.user_id', '=', 'b.user_id')
            ->leftJoin('user_address as ua', function ($j) use ($complexId) {
                $j->on('ua.user_id', '=', 'b.user_id')
                    ->where('ua.complex_id', '=', $complexId);
            });

        if ($torre) {
            if ($torre === 'sin') {
                $consulta->whereNull('ua.tower');
            } else {
                $consulta->where('ua.tower', $torre);
            }
        }

        if ($buscar) {
            $consulta->where(function ($q) use ($buscar) {
                // Escapado: un `%` o un `_` del buscador no son comodines.
                Like::contiene($q, 'u.name', $buscar);
                Like::contiene($q, 'ua.apartment', $buscar, 'or');
            });
        }

        return $consulta
            ->orderByRaw('ua.tower IS NULL, ua.tower')
            ->orderByRaw('CAST(ua.apartment AS UNSIGNED), ua.apartment')
            ->select(['b.buyer_id', 'u.name', 'ua.tower', 'ua.apartment', 'b.state'])
            ->distinct()
            // Tope: el panel pide de a una torre, pero sin `tower` esto
            // traería el conjunto entero a memoria en cada página.
            ->limit(5000)
            ->get();
    }

    /**
     * Los vecinos del conjunto, para agrupar por torre.
     *
     * @return Collection<int, stdClass>
     */
    public function vecinos(int $complexId): Collection
    {
        return DB::table('buyer_complex as bc')
            ->where('bc.complex_id', $complexId)
            ->join('buyer as b', 'b.buyer_id', '=', 'bc.buyer_id')
            ->leftJoin('user_address as ua', function ($j) use ($complexId) {
                $j->on('ua.user_id', '=', 'b.user_id')
                    ->where('ua.complex_id', '=', $complexId);
            })
            ->get(['b.buyer_id', 'ua.tower', 'ua.apartment', 'bc.created_at']);
    }

    /** Cuántas torres y cuántos apartamentos por torre tiene el conjunto. */
    public function formaDelConjunto(int $complexId): ?object
    {
        return DB::table('residential_complexes')
            ->where('complex_id', $complexId)
            ->first(['towers_count', 'apartments_per_tower']);
    }

    /**
     * Domicilios de la plataforma que entraron por la portería, por puerta.
     *
     * Sale del libro de entradas: cada entrada de un domiciliario guarda a qué
     * torre y apartamento iba cada pedido. Se recorre en PHP porque el destino
     * vive dentro del JSON de la entrada, y leerlo en SQL ataría la consulta a
     * un motor.
     *
     * @return array<string, array{n:int, ultimo:?string}>
     */
    public function domiciliosPorPuerta(int $complexId, CarbonImmutable $desde): array
    {
        $puertas = [];

        DB::table('complex_entries')
            ->where('complex_id', $complexId)
            ->where('kind', 'domiciliario')
            ->where('created_at', '>=', $desde)
            ->select(['id', 'orders', 'created_at'])
            ->chunkById(500, function ($entradas) use (&$puertas) {
                foreach ($entradas as $e) {
                    $pedidos = is_string($e->orders) ? json_decode($e->orders, true) : [];

                    foreach ($pedidos ?: [] as $p) {
                        if (empty($p['tower'])) {
                            continue;
                        }

                        $k = $this->puerta($p['tower'], $p['apartment'] ?? '');
                        $puertas[$k]['n'] = ($puertas[$k]['n'] ?? 0) + 1;

                        $ultimo = $puertas[$k]['ultimo'] ?? null;
                        if (! $ultimo || (string) $e->created_at > $ultimo) {
                            $puertas[$k]['ultimo'] = (string) $e->created_at;
                        }
                    }
                }
            });

        return $puertas;
    }

    /** La clave de una puerta: torre y apartamento. */
    public function puerta($torre, $apto): string
    {
        return trim((string) $torre).'|'.trim((string) $apto);
    }
}
