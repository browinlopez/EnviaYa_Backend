<?php

namespace App\Exports\Comercials;

use App\Models\OrderSale;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithTitle;
use Maatwebsite\Excel\Concerns\WithStyles;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class UsersSheet implements FromCollection, WithHeadings, WithTitle, WithStyles
{
    protected $start;
    protected $end;

    public function __construct($start, $end)
    {
        $this->start = $start;
        $this->end = $end;
    }

    public function collection()
    {
        $orders = OrderSale::with('buyer.user', 'payments')
            ->whereBetween('sale_date', [$this->start, $this->end])
            ->get();

        $users = $orders->groupBy('buyer_id')->map(function ($userOrders) {
            $firstOrder = $userOrders->sortBy('sale_date')->first();
            $isNew = $firstOrder->sale_date >= $this->start && $firstOrder->sale_date <= $this->end;

            $totalSpent = $userOrders->sum(function ($order) {
                return $order->payments?->total ?? 0;
            });

            return [
                'Usuario' => $userOrders->first()->buyer?->user?->name ?? 'N/A',
                'Tipo de Usuario' => $isNew ? 'Nuevo' : 'Recurrente',
                'Pedidos Realizados' => $userOrders->count(),
                'Total Gastado' => $totalSpent,
            ];
        });

        return $users;
    }

    public function headings(): array
    {
        return ['Usuario', 'Tipo de Usuario', 'Pedidos Realizados', 'Total Gastado'];
    }

    public function title(): string
    {
        return 'Usuarios';
    }

    public function styles(Worksheet $sheet)
    {
        $sheet->getStyle('A1:D1')->getFont()->setBold(true);
        return [];
    }
}
