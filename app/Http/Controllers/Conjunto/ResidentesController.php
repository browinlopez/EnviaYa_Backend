<?php

namespace App\Http\Controllers\Conjunto;

use App\Http\Controllers\Controller;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ResidentesController extends Controller
{
    /* --------------------------- RESIDENTES --------------------------- */

    /**
     * Quiénes son, no cuántos: el detalle de una torre.
     *
     * SIN CORREO NI TELÉFONO, y no es un olvido. Una administración lleva
     * legítimamente el registro de quién vive en su edificio —eso es lo que se
     * enseña acá—, pero los datos de CONTACTO de cada vecino son de su
     * relación con la plataforma, no con el conjunto.
     *
     * Lleva cuántos domicilios le entraron por la portería y cuándo fue el
     * último: eso es el libro de entradas del edificio, que es de la
     * administración. Qué pidió, a qué tienda y cuánto pagó, no.
     *
     * Paginado: se pide de a una torre (unos cientos de vecinos como mucho), y
     * el orden por domicilios exige contar antes de cortar la página.
     */
    public function residentesDetalle(Request $request)
    {
        $complexId = (int) $request->attributes->get('complex_id');

        $porPagina = min(100, max(10, (int) $request->query('per_page', 20)));

        $consulta = DB::table('buyer_complex as bc')
            ->where('bc.complex_id', $complexId)
            ->join('buyer as b', 'b.buyer_id', '=', 'bc.buyer_id')
            ->join('user as u', 'u.user_id', '=', 'b.user_id')
            ->leftJoin('user_address as ua', function ($j) use ($complexId) {
                $j->on('ua.user_id', '=', 'b.user_id')
                    ->where('ua.complex_id', '=', $complexId);
            });

        if ($torre = $request->query('tower')) {
            // `sin` es la torre de quienes se registraron antes de que el
            // campo existiera: no es un valor, es su ausencia.
            if ($torre === 'sin') {
                $consulta->whereNull('ua.tower');
            } else {
                $consulta->where('ua.tower', $torre);
            }
        }

        if ($buscar = trim((string) $request->query('search'))) {
            $consulta->where(function ($q) use ($buscar) {
                $q->where('u.name', 'like', "%{$buscar}%")
                    ->orWhere('ua.apartment', 'like', "%{$buscar}%");
            });
        }

        $filas = $consulta
            ->orderByRaw('ua.tower IS NULL, ua.tower')
            ->orderByRaw('CAST(ua.apartment AS UNSIGNED), ua.apartment')
            ->select(['b.buyer_id', 'u.name', 'ua.tower', 'ua.apartment', 'b.state'])
            ->distinct()
            // Tope: el panel pide de a una torre, pero sin `tower` esto
            // traería el conjunto entero a memoria en cada página.
            ->limit(5000)
            ->get();

        /*
         * Los domicilios se cuentan por APARTAMENTO: el libro sabe a qué
         * puerta iba el pedido, no quién de la casa lo pidió.
         */
        [$desde] = $this->ventana($request);
        $puertas = $this->domiciliosPorPuerta($complexId, $desde);

        $filas = $filas->map(function ($f) use ($puertas) {
            $p = $puertas[$this->puerta($f->tower, $f->apartment)] ?? null;
            $f->domicilios = $p['n'] ?? 0;
            $f->ultimo_domicilio = $p['ultimo'] ?? null;

            return $f;
        });

        if ($request->query('orden') === 'domicilios') {
            $filas = $filas->sortByDesc('domicilios')->values();
        }

        $pagina = max(1, (int) $request->query('page', 1));
        $total  = $filas->count();

        return response()->json([
            'data' => $filas->forPage($pagina, $porPagina)->values(),
            'meta' => [
                'page'      => $pagina,
                'per_page'  => $porPagina,
                'total'     => $total,
                'last_page' => max(1, (int) ceil($total / $porPagina)),
                'desde'     => $desde->toDateString(),
                // Si se llegó al tope, el total no es el del conjunto: se dice.
                'truncado'  => $total >= 5000,
            ],
        ]);
    }

    /**
     * Cuántos vecinos usan la plataforma, por torre, y cuántos domicilios les
     * entraron por la portería. Sin nombres: eso es el detalle de cada torre.
     */
    public function residentes(Request $request)
    {
        $complexId = (int) $request->attributes->get('complex_id');

        $vecinos = DB::table('buyer_complex as bc')
            ->where('bc.complex_id', $complexId)
            ->join('buyer as b', 'b.buyer_id', '=', 'bc.buyer_id')
            ->leftJoin('user_address as ua', function ($j) use ($complexId) {
                $j->on('ua.user_id', '=', 'b.user_id')
                    ->where('ua.complex_id', '=', $complexId);
            })
            ->get(['b.buyer_id', 'ua.tower', 'ua.apartment', 'bc.created_at']);

        $conjunto = DB::table('residential_complexes')
            ->where('complex_id', $complexId)
            ->first(['towers_count', 'apartments_per_tower']);

        $porTorre = (int) ($conjunto->apartments_per_tower ?? 0);
        $unidades = (int) ($conjunto->towers_count ?? 0) * $porTorre;

        [$desde, $ventana] = $this->ventana($request);
        $puertas = $this->domiciliosPorPuerta($complexId, $desde);

        $domiciliosTorre = [];
        foreach ($puertas as $clave => $p) {
            $torre = explode('|', $clave)[0];
            $domiciliosTorre[$torre] = ($domiciliosTorre[$torre] ?? 0) + $p['n'];
        }

        $filas = $vecinos->groupBy(fn ($v) => (string) ($v->tower ?? ''))
            ->map(fn ($grupo, $torre) => [
                'tower'      => $torre === '' ? null : (string) $torre,
                'residentes' => $grupo->unique('buyer_id')->count(),
                // Apartamentos con al menos un vecino en la plataforma: el
                // «uso» de la torre se mide en hogares, no en personas.
                'unidades_con_uno' => $grupo->filter(fn ($v) => $v->apartment)
                    ->unique(fn ($v) => trim((string) $v->apartment))->count(),
                'unidades'   => $torre === '' ? null : ($porTorre ?: null),
                'domicilios' => $torre === '' ? 0 : (int) ($domiciliosTorre[(string) $torre] ?? 0),
            ])
            ->sortBy(fn ($f) => sprintf(
                '%d-%010d-%s',
                $f['tower'] === null ? 1 : 0,
                is_numeric($f['tower']) ? (int) $f['tower'] : 9999999999,
                $f['tower'],
            ))
            ->values();

        $inicioMes   = CarbonImmutable::now()->startOfMonth();
        $inicioAntes = $inicioMes->subMonthNoOverflow();
        $nuevosEntre = fn ($desde, $hasta) => $vecinos
            ->filter(fn ($v) => $v->created_at
                && $v->created_at >= $desde->toDateTimeString()
                && (!$hasta || $v->created_at < $hasta->toDateTimeString()))
            ->unique('buyer_id')->count();

        return response()->json([
            'data'    => $filas,
            'total'   => (int) $vecinos->unique('buyer_id')->count(),
            'resumen' => [
                'unidades'            => $unidades ?: null,
                'unidades_con_uno'    => (int) $filas->sum('unidades_con_uno'),
                'domicilios'          => (int) array_sum($domiciliosTorre),
                'ventana'             => $ventana,
                'desde'               => $desde->toDateString(),
                'nuevos_mes'          => $nuevosEntre($inicioMes, null),
                'nuevos_mes_anterior' => $nuevosEntre($inicioAntes, $inicioMes),
                'mes_anterior'        => $inicioAntes->locale('es')->isoFormat('MMMM'),
            ],
        ]);
    }

    /** La ventana de los domicilios: los últimos 30 días o este mes. */
    private function ventana(Request $request): array
    {
        return $request->query('ventana') === 'mes'
            ? [CarbonImmutable::now()->startOfMonth(), 'mes']
            : [CarbonImmutable::now()->subDays(29)->startOfDay(), '30'];
    }

    private function puerta($torre, $apto): string
    {
        return trim((string) $torre) . '|' . trim((string) $apto);
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
    private function domiciliosPorPuerta(int $complexId, CarbonImmutable $desde): array
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
                        if (!$ultimo || (string) $e->created_at > $ultimo) {
                            $puertas[$k]['ultimo'] = (string) $e->created_at;
                        }
                    }
                }
            });

        return $puertas;
    }
}
