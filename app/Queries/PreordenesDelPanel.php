<?php

declare(strict_types=1);

namespace App\Queries;

use App\Models\Chat\Preorden;
use App\Models\Chat\PreordenEvento;
use App\Services\PreordenDelChat;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

// Las cifras de las preórdenes para el panel, todas sobre la tabla y sus índices.
class PreordenesDelPanel
{
    /** @return array<string, mixed> */
    public function resumen(int $dias): array
    {
        $desde = now()->subDays($dias);
        $filas = Preorden::where('created_at', '>=', $desde)->get();

        $creadas = $filas->count();
        $pagadas = $filas->where('status', PreordenDelChat::PAGADA);
        $ajustadas = $filas->where('ajustada', true);

        return [
            'days' => $dias,
            'creadas' => $creadas,
            'confirmadas' => $filas->whereNotNull('confirmed_at')->count(),
            'pagadas' => $pagadas->count(),
            'canceladas' => $filas->where('status', PreordenDelChat::CANCELADA)->count(),
            'pendientes' => $filas->where('status', PreordenDelChat::PENDIENTE)->count(),
            'ajustadas' => $ajustadas->count(),
            'del_cliente' => $filas->where('de', 'cliente')->count(),
            'del_negocio' => $filas->where('de', 'negocio')->count(),
            'conversion' => $creadas > 0 ? round($pagadas->count() / $creadas, 4) : 0,
            'monto_pagado' => round((float) $pagadas->sum('total'), 2),
            ...$this->cuantoSeAjusta($ajustadas->pluck('total', 'id')->all()),
            'negocios' => $this->porNegocio($filas),
            'recientes' => $this->recientes($filas),
        ];
    }

    /**
     * @param  array<int, mixed>  $totales  id => total final
     * @return array{ajuste_total: float, ajuste_promedio: float}
     */
    private function cuantoSeAjusta(array $totales): array
    {
        if ($totales === []) {
            return ['ajuste_total' => 0.0, 'ajuste_promedio' => 0.0];
        }

        // Lo que pidió el cliente sale del primer evento; lo que quedó, de la fila.
        $originales = PreordenEvento::whereIn('preorder_id', array_keys($totales))
            ->whereIn('accion', ['creada', 'migrada'])
            ->get(['preorder_id', 'despues'])
            ->mapWithKeys(fn ($e) => [$e->preorder_id => (float) ($e->despues['total'] ?? 0)]);

        $diferencias = collect($totales)
            ->map(fn ($final, $id) => max(0, ($originales[$id] ?? (float) $final) - (float) $final));

        return [
            'ajuste_total' => round($diferencias->sum(), 2),
            'ajuste_promedio' => round($diferencias->avg() ?? 0, 2),
        ];
    }

    /**
     * @param  Collection<int, Preorden>  $filas
     * @return array<int, array<string, mixed>>
     */
    private function porNegocio($filas): array
    {
        $nombres = DB::table('business')->whereIn('busines_id', $filas->pluck('busines_id')->filter())->pluck('name', 'busines_id');

        return $filas->groupBy('busines_id')
            ->map(fn ($del, $id) => [
                'busines_id' => (int) $id,
                'nombre' => $nombres[$id] ?? 'Sin negocio',
                'creadas' => $del->count(),
                'pagadas' => $del->where('status', PreordenDelChat::PAGADA)->count(),
                'canceladas' => $del->where('status', PreordenDelChat::CANCELADA)->count(),
                'ajustadas' => $del->where('ajustada', true)->count(),
                'monto_pagado' => round((float) $del->where('status', PreordenDelChat::PAGADA)->sum('total'), 2),
            ])
            ->sortByDesc('creadas')
            ->take(10)
            ->values()
            ->all();
    }

    /**
     * @param  Collection<int, Preorden>  $filas
     * @return array<int, array<string, mixed>>
     */
    private function recientes($filas): array
    {
        $ultimas = $filas->sortByDesc('id')->take(15);
        $negocios = DB::table('business')->whereIn('busines_id', $ultimas->pluck('busines_id')->filter())->pluck('name', 'busines_id');
        $clientes = DB::table('user')->whereIn('user_id', $ultimas->pluck('buyer_user_id')->filter())->pluck('name', 'user_id');

        return $ultimas->map(fn (Preorden $f) => [
            'id' => $f->id,
            'chat_id' => $f->chat_id,
            'negocio' => $negocios[$f->busines_id] ?? 'Sin negocio',
            'cliente' => $clientes[$f->buyer_user_id] ?? 'Sin cliente',
            'de' => $f->de,
            'status' => $f->status,
            'total' => (float) $f->total,
            'ajustada' => $f->ajustada,
            'productos' => count($f->items),
            'order_id' => $f->order_id,
            'created_at' => $f->created_at?->toIso8601String(),
        ])->values()->all();
    }
}
