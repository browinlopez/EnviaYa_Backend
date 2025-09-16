<?php

namespace App\Exports\Comercials;

use App\Models\Order\OrdersSales;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithTitle;
use Maatwebsite\Excel\Concerns\WithStyles;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class OrdersSheet implements FromCollection, WithHeadings, WithTitle, WithStyles
{
    protected $start;
    protected $end;

    public function __construct($start, $end)
    {
        $this->start = $start;
        $this->end   = $end;
    }

    public function collection()
    {
        return OrdersSales::with(['buyer.user', 'business', 'payments'])
            ->whereBetween('sale_date', [$this->start, $this->end])
            ->get()
            ->map(function ($order) {
                $payment = $order->payments; // trae el hasOne payment

                return [
                    'ID Pedido'       => $order->orderSales_id,
                    'Cliente'         => $order->buyer?->user?->name ?? 'N/A',
                    'Negocio'         => $order->business?->name ?? 'N/A',
                    'Subtotal'        => $payment?->subtotal ?? 0,
                    'Descuento'       => $payment?->valor_promocion ?? 0,
                    'Total'           => $payment?->total ?? 0,
                    'Costo Domicilio' => $payment?->domicilio ?? 0,
                    'Fecha'           => $order->sale_date,
                ];
            });
    }

    public function headings(): array
    {
        return ['ID Pedido', 'Cliente', 'Negocio', 'Subtotal', 'Descuento', 'Total', 'Costo Domicilio', 'Fecha'];
    }

    public function title(): string
    {
        return 'Pedidos';
    }

    public function styles(Worksheet $sheet)
    {
        $sheet->getStyle('A1:H1')->getFont()->setBold(true);
        $sheet->setAutoFilter($sheet->calculateWorksheetDimension());
        return [];
    }
}
