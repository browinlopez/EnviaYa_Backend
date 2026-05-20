<?php

namespace App\Exports\operational;

use App\Models\OrderSale;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithTitle;

class ResumenOperativoSheet implements FromArray, WithTitle
{
    public function __construct(protected $start, protected $end)
    {
    }

    public function array(): array
    {
        $orders = OrderSale::whereBetween('sale_date', [$this->start, $this->end])
            ->whereNotNull('delivery_date')
            ->get();

        $tiempos = $orders->map(
            fn($o) =>
            $o->delivery_date && $o->sale_date
            ? now()->parse($o->delivery_date)->diffInMinutes($o->sale_date)
            : null
        )->filter();

        $promedioEntrega = $tiempos->avg() ?? 0;

        $cancelados = OrderSale::whereBetween('sale_date', [$this->start, $this->end])
            ->where('state', 'cancelado') // ajusta al estado real
            ->count();

        $totalPedidos = OrderSale::whereBetween('sale_date', [$this->start, $this->end])->count();

        return [
            ['Indicador', 'Valor'],
            ['Tiempo Promedio de Entrega (min)', round($promedioEntrega, 1)],
            ['Tasa de Cancelaciones (%)', $totalPedidos ? round(($cancelados / $totalPedidos) * 100, 2) : 0],
        ];
    }

    public function title(): string
    {
        return 'Resumen';
    }
}