<?php

namespace App\Exports\Comercials;

use App\Models\Order\OrdersSales;
use App\Models\Business;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithTitle;
use Maatwebsite\Excel\Concerns\WithStyles;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class ResumenSheet implements FromArray, WithTitle, WithStyles
{
    protected $start;
    protected $end;

    public function __construct($start, $end)
    {
        $this->start = $start;
        $this->end   = $end;
    }

    public function array(): array
    {
        // Ticket promedio
        $avgTicket = OrdersSales::whereBetween('sale_date', [$this->start, $this->end])->avg('total');

        // Usuarios
        $buyersIds = OrdersSales::whereBetween('sale_date', [$this->start, $this->end])
            ->pluck('buyer_id')->unique();

        $totalActiveUsers = $buyersIds->count();

        $newUsers = OrdersSales::select('buyer_id')
            ->whereIn('buyer_id', $buyersIds)
            ->groupBy('buyer_id')
            ->havingRaw('MIN(sale_date) BETWEEN ? AND ?', [$this->start, $this->end])
            ->count();

        $recurrentUsers = $totalActiveUsers - $newUsers;

        // Tiendas activas/inactivas
        $businessWithOrders = OrdersSales::whereBetween('sale_date', [$this->start, $this->end])
            ->distinct('busines_id')
            ->count('busines_id');

        $totalBusinesses = Business::count();
        $inactiveBusinesses = $totalBusinesses - $businessWithOrders;

        // Tasa repetición
        $repeatCustomers = OrdersSales::whereBetween('sale_date', [$this->start, $this->end])
            ->select('buyer_id')
            ->groupBy('buyer_id')
            ->havingRaw('COUNT(*) > 1')
            ->count();

        $repeatRate = $totalActiveUsers > 0 ? round(($repeatCustomers / $totalActiveUsers) * 100, 2) : 0;

        // CAC simple
        $adSpend = 1000000;
        $CAC = $totalActiveUsers > 0 ? $adSpend / $totalActiveUsers : 0;

        return [
            ['Indicador', 'Valor'],
            ['Ticket promedio', $avgTicket],
            ['Usuarios nuevos', $newUsers],
            ['Usuarios recurrentes', $recurrentUsers],
            ['Tasa repetición (%)', $repeatRate],
            ['Tiendas activas', $businessWithOrders],
            ['Tiendas inactivas', $inactiveBusinesses],
            ['CAC', $CAC],
        ];
    }

    public function title(): string
    {
        return 'Resumen';
    }

    public function styles(Worksheet $sheet)
    {
        $sheet->getStyle('A1:B1')->getFont()->setBold(true);
        return [];
    }
}
