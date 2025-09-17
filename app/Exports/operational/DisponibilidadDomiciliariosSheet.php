<?php

namespace App\Exports\operational;

use App\Models\Domiciliary;
use App\Models\Order\OrdersSales;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithTitle;

class DisponibilidadDomiciliariosSheet implements FromArray, WithTitle
{
    public function __construct(protected $start, protected $end) {}

    public function array(): array
    {
        // Domiciliarios conectados
        $conectados = Domiciliary::where('available', true)->count();

        // Pedidos activos (en curso) en rango
        $pedidosActivos = OrdersSales::whereBetween('sale_date', [$this->start, $this->end])
            ->whereIn('state', ['pendiente', 'en_proceso']) // ajusta tus estados activos reales
            ->count();

        return [
            ['Indicador', 'Valor'],
            ['Domiciliarios conectados', $conectados],
            ['Pedidos activos', $pedidosActivos],
        ];
    }

    public function title(): string
    {
        return 'Disponibilidad Domiciliarios';
    }
}
