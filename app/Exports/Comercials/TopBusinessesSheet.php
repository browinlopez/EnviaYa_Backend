<?php

namespace App\Exports\Comercials;

use App\Models\OrderSale;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithTitle;
use Maatwebsite\Excel\Concerns\WithStyles;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class TopBusinessesSheet implements FromCollection, WithHeadings, WithTitle, WithStyles
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
        return OrderSale::with('business')
            ->whereBetween('sale_date', [$this->start, $this->end])
            ->selectRaw('business_id, COUNT(*) as total_orders, SUM(total) as total_amount')
            ->groupBy('business_id')
            ->get()
            ->map(function ($item) {
                return [
                    'Negocio' => $item->business?->name ?? 'N/A',
                    'Total Pedidos' => $item->total_orders,
                    'Total Facturado' => $item->total_amount,
                ];
            });
    }

    public function headings(): array
    {
        return ['Negocio', 'Total Pedidos', 'Total Facturado'];
    }

    public function title(): string
    {
        return 'Top Negocios';
    }

    public function styles(Worksheet $sheet)
    {
        $sheet->getStyle('A1:C1')->getFont()->setBold(true);
        $sheet->setAutoFilter($sheet->calculateWorksheetDimension());
        return [];
    }
}
