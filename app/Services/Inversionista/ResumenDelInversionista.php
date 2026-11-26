<?php

declare(strict_types=1);

namespace App\Services\Inversionista;

use Carbon\Carbon;
use Illuminate\Database\Query\Builder;

/**
 * RESUMEN: LAS CUATRO CIFRAS, LAS VENTAS, EL REPARTO POR TIPO Y EL AÑO.
 *
 * Es la portada del panel del inversionista: negocios activos, usuarios
 * registrados, pedidos realizados y ticket promedio (cada uno contra el
 * periodo anterior de igual tamaño), las ventas del periodo, cuántos negocios
 * hay de cada tipo y la serie de doce meses.
 *
 * El reparto por tipo y la serie de meses también los usan Negocios e
 * Informes: por eso son públicos.
 */
class ResumenDelInversionista extends BaseDelInversionista
{
    public function datos(array $v, array $f): array
    {
        return [
            'cifras' => $this->cifras($v, $f),
            'por_tipo' => $this->porTipo($v, $f),
            'meses' => $this->meses($v['hasta'], $f),
        ];
    }

    /* ------------------------------ CIFRAS ------------------------------ */

    public function cifras(array $v, array $f): array
    {
        $negocios = $this->negociosAl($f, $v['hasta'])->count();
        $negociosAntes = $this->negociosAl($f, $v['hasta_antes'])->count();

        $conPedidos = $this->entregados($f, $v['desde'], $v['hasta'], true)
            ->where('b.state', 1)
            ->distinct()
            ->count('o.busines_id');

        $usuarios = $this->compradoresAl($f, $v['hasta'])->count();
        $usuariosAntes = $this->compradoresAl($f, $v['hasta_antes'])->count();
        $nuevos = $this->compradores($f)->whereBetween('u.created_at', [$v['desde'], $v['hasta']])->count();
        $nuevosAntes = $this->compradores($f)->whereBetween('u.created_at', [$v['desde_antes'], $v['hasta_antes']])->count();

        [$pedidos, $ventas] = $this->totales($this->entregados($f, $v['desde'], $v['hasta']));
        [$pedidosAntes, $ventasAntes] = $this->totales($this->entregados($f, $v['desde_antes'], $v['hasta_antes']));

        $ticket = $pedidos > 0 ? round($ventas / $pedidos, 2) : null;
        $ticketAntes = $pedidosAntes > 0 ? round($ventasAntes / $pedidosAntes, 2) : null;

        return [
            'negocios_activos' => $this->cifra($negocios, $negociosAntes) + ['con_pedidos' => $conPedidos],
            'usuarios_registrados' => $this->cifra($usuarios, $usuariosAntes) + [
                'nuevos' => $nuevos,
                'nuevos_anterior' => $nuevosAntes,
            ],
            'pedidos' => $this->cifra($pedidos, $pedidosAntes),
            'ventas' => $this->cifra($ventas, $ventasAntes),
            'ticket_promedio' => $this->cifra($ticket, $ticketAntes),
        ];
    }

    /** @return array{0:int, 1:float} pedidos y ventas */
    private function totales(Builder $q): array
    {
        $fila = $q->selectRaw('COUNT(*) as n, COALESCE(SUM(o.total), 0) as ventas')->first();

        return [(int) ($fila->n ?? 0), $this->pesos($fila->ventas ?? 0)];
    }

    /* ----------------------------- POR TIPO ----------------------------- */

    /**
     * Los negocios activos de cada oficio, con lo que movieron en el periodo
     * y su parte de las ventas.
     *
     * NO lleva el filtro de tipo, a propósito: es el reparto entre tipos, y
     * filtrado por uno solo quedaría una tarjeta al 100 % y tres en cero. Sí
     * lleva el de ciudad.
     */
    public function porTipo(array $v, array $f): array
    {
        $sinTipo = ['municipality_id' => $f['municipality_id'], 'tipo' => null];

        $negocios = $this->negociosAl($sinTipo, $v['hasta'])
            ->selectRaw('b.type as tipo, COUNT(*) as n')
            ->groupBy('b.type')
            ->pluck('n', 'tipo');

        $pedidos = $this->entregados($sinTipo, $v['desde'], $v['hasta'], true)
            ->selectRaw('b.type as tipo, COUNT(*) as n, COALESCE(SUM(o.total), 0) as ventas')
            ->groupBy('b.type')
            ->get()
            ->keyBy('tipo');

        $total = (int) $negocios->sum();
        $totalPedidos = (int) $pedidos->sum('n');
        $totalVentas = (float) $pedidos->sum('ventas');

        return array_map(function (array $t) use ($negocios, $pedidos, $total, $totalPedidos, $totalVentas) {
            $n = (int) ($negocios[$t['id']] ?? 0);
            $p = $pedidos[$t['id']] ?? null;
            $ventas = $this->pesos($p->ventas ?? 0);

            return $t + [
                'negocios' => $n,
                'porcentaje' => $total > 0 ? round($n / $total * 100, 1) : 0,
                'pedidos' => (int) ($p->n ?? 0),
                'ventas' => $ventas,
                // Qué parte de lo que se vendió (y de los pedidos) es de este oficio.
                'participacion_ventas' => $totalVentas > 0 ? round($ventas / $totalVentas * 100, 1) : 0,
                'participacion_pedidos' => $totalPedidos > 0 ? round((int) ($p->n ?? 0) / $totalPedidos * 100, 1) : 0,
            ];
        }, $this->tipos());
    }

    /* ------------------------------ MESES ------------------------------- */

    /**
     * Los doce meses que terminan en el mes de `hasta`, con su variación.
     *
     * Se piden trece para que el primero de los doce también tenga contra
     * qué compararse.
     */
    public function meses(Carbon $hasta, array $f): array
    {
        [$inicio, $fin, $meses] = $this->mesesHasta($hasta, 1);

        $pedidos = $this->entregados($f, $inicio, $fin)
            ->selectRaw("{$this->mesDe('o.sale_date')} as mes, COUNT(*) as n, COALESCE(SUM(o.total), 0) as ventas")
            ->groupBy('mes')
            ->get()
            ->keyBy('mes');

        $negocios = $this->negocios($f)
            ->whereBetween('b.created_at', [$inicio, $fin])
            ->selectRaw("{$this->mesDe('b.created_at')} as mes, COUNT(*) as n")
            ->groupBy('mes')
            ->pluck('n', 'mes');

        $usuarios = $this->compradores($f)
            ->whereBetween('u.created_at', [$inicio, $fin])
            ->selectRaw("{$this->mesDe('u.created_at')} as mes, COUNT(*) as n")
            ->groupBy('mes')
            ->pluck('n', 'mes');

        $serie = [];
        $previo = null;

        foreach ($meses as $m) {
            $clave = $m['mes'];
            $fila = $this->rotuloDeMes($m) + [
                'pedidos' => (int) ($pedidos[$clave]->n ?? 0),
                'ventas' => $this->pesos($pedidos[$clave]->ventas ?? 0),
                'negocios_nuevos' => (int) ($negocios[$clave] ?? 0),
                'usuarios_nuevos' => (int) ($usuarios[$clave] ?? 0),
            ];

            $fila['variacion'] = [];
            foreach (['pedidos', 'ventas', 'negocios_nuevos', 'usuarios_nuevos'] as $k) {
                $fila['variacion'][$k] = $this->variacion($fila[$k], $previo[$k] ?? null);
            }

            $serie[] = $fila;
            $previo = $fila;
        }

        // El mes número trece solo servía de base para la primera variación.
        return array_slice($serie, 1);
    }
}
