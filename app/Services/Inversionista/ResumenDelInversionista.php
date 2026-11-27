<?php

declare(strict_types=1);

namespace App\Services\Inversionista;

use Carbon\Carbon;

/**
 * RESUMEN: LAS CUATRO CIFRAS, EL REPARTO POR TIPO Y EL AÑO.
 *
 * Es la portada del panel del inversionista: negocios activos, usuarios
 * registrados, pedidos realizados y usuarios activos (cada uno contra el
 * periodo anterior de igual tamaño), cuántos negocios hay de cada tipo y la
 * serie de doce meses.
 *
 * SIN PESOS. El inversionista ve el crecimiento —negocios, personas,
 * pedidos, ciudades, conjuntos— y no la plata: ni ventas, ni ticket, ni
 * comisiones. No es que la pantalla las esconda: no salen de aquí.
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

        $pedidos = $this->entregados($f, $v['desde'], $v['hasta'])->count();
        $pedidosAntes = $this->entregados($f, $v['desde_antes'], $v['hasta_antes'])->count();

        /*
         * USUARIOS ACTIVOS: compradores distintos con al menos un pedido
         * entregado en el periodo. Es la cifra que dice si la gente vuelve,
         * y ocupa el lugar que tenía el ticket promedio (que era plata).
         */
        $activos = fn ($desde, $hasta) => $this->entregados($f, $desde, $hasta)
            ->distinct()
            ->count('o.buyer_id');

        return [
            'negocios_activos' => $this->cifra($negocios, $negociosAntes) + ['con_pedidos' => $conPedidos],
            'usuarios_registrados' => $this->cifra($usuarios, $usuariosAntes) + [
                'nuevos' => $nuevos,
                'nuevos_anterior' => $nuevosAntes,
            ],
            'pedidos' => $this->cifra($pedidos, $pedidosAntes),
            'usuarios_activos' => $this->cifra(
                $activos($v['desde'], $v['hasta']),
                $activos($v['desde_antes'], $v['hasta_antes']),
            ),
        ];
    }

    /* ----------------------------- POR TIPO ----------------------------- */

    /**
     * Los negocios activos de cada oficio, con los pedidos que entregaron en
     * el periodo y su parte de los pedidos.
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
            ->selectRaw('b.type as tipo, COUNT(*) as n')
            ->groupBy('b.type')
            ->get()
            ->keyBy('tipo');

        $total = (int) $negocios->sum();
        $totalPedidos = (int) $pedidos->sum('n');

        return array_map(function (array $t) use ($negocios, $pedidos, $total, $totalPedidos) {
            $n = (int) ($negocios[$t['id']] ?? 0);
            $p = $pedidos[$t['id']] ?? null;

            return $t + [
                'negocios' => $n,
                'porcentaje' => $total > 0 ? round($n / $total * 100, 1) : 0,
                'pedidos' => (int) ($p->n ?? 0),
                // Qué parte de los pedidos es de este oficio.
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
            ->selectRaw("{$this->mesDe('o.sale_date')} as mes, COUNT(*) as n")
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
                'negocios_nuevos' => (int) ($negocios[$clave] ?? 0),
                'usuarios_nuevos' => (int) ($usuarios[$clave] ?? 0),
            ];

            $fila['variacion'] = [];
            foreach (['pedidos', 'negocios_nuevos', 'usuarios_nuevos'] as $k) {
                $fila['variacion'][$k] = $this->variacion($fila[$k], $previo[$k] ?? null);
            }

            $serie[] = $fila;
            $previo = $fila;
        }

        // El mes número trece solo servía de base para la primera variación.
        return array_slice($serie, 1);
    }
}
