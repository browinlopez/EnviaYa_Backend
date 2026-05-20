<?php

namespace App\Exports\Financial;

use App\Models\Payment;
use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithTitle;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithStyles;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class OrdersDetailSheet implements FromCollection, WithTitle, WithHeadings, WithStyles
{
    protected $business_id, $domiciliary_id, $date_start, $date_end;

    public function __construct($business_id, $domiciliary_id, $date_start, $date_end)
    {
        $this->business_id = $business_id;
        $this->domiciliary_id = $domiciliary_id;
        $this->date_start = $date_start;
        $this->date_end = $date_end;
    }

    public function collection()
    {
        return Payment::query()
            ->select(
                DB::raw('payments.payment_date as fecha_pago'),
                'payments.order_sale_id as pedido_id',
                'business.name as negocio',
                'u.name as domiciliario',
                DB::raw('payments.subtotal as subtotal'),
                DB::raw('payments.domicilio as valor_domicilio'),
                DB::raw('payments.valor_promocion as descuento'),
                DB::raw('payments.total as total')
            )
            ->join('OrderSale', 'OrderSale.order_sale_id', '=', 'payments.order_sale_id')
            ->join('business', 'business.busines_id', '=', 'OrderSale.busines_id')
            ->join('domiciliary', 'domiciliary.domiciliary_id', '=', 'OrderSale.domiciliary_id')
            ->join('user as u', 'u.user_id', '=', 'domiciliary.user_id')
            ->when(
                $this->business_id,
                fn($q) =>
                $q->where('OrderSale.busines_id', $this->business_id)
            )
            ->when(
                $this->domiciliary_id,
                fn($q) =>
                $q->where('OrderSale.domiciliary_id', $this->domiciliary_id)
            )
            ->whereBetween('payments.payment_date', [$this->date_start, $this->date_end])
            ->orderBy('payments.payment_date')
            ->get();
    }

    public function title(): string
    {
        return 'Detalle Órdenes';
    }

    public function headings(): array
    {
        return [
            'Fecha Pago',
            'Pedido ID',
            'Negocio',
            'Domiciliario',
            'Subtotal',
            'Valor Domicilio',
            'Descuento',
            'Total'
        ];
    }

    public function styles(Worksheet $sheet)
    {
        // Negrita en encabezados
        $sheet->getStyle('A1:H1')->getFont()->setBold(true);
        // Filtros automáticos
        $sheet->setAutoFilter('A1:H1');
        // Ajustar ancho
        foreach (range('A', 'H') as $col) {
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }
    }
}