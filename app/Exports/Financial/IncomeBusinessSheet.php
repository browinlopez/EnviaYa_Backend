<?php

namespace App\Exports\Financial;

use App\Models\Payment;
use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithTitle;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithStyles;

class IncomeBusinessSheet implements FromCollection, WithStyles, WithTitle, WithHeadings
{
    protected $business_id, $date_start, $date_end;

    public function __construct($business_id, $date_start, $date_end)
    {
        $this->business_id = $business_id;
        $this->date_start = $date_start;
        $this->date_end = $date_end;
    }

    public function headings(): array
    {
        return ['Mes', 'Negocio', 'Pedidos', 'Total ingresos'];
    }

    public function collection()
    {
        return Payment::query()
            ->select(
                DB::raw("DATE_FORMAT(payments.payment_date,'%Y-%m') as mes"),
                'business.name as negocio',
                DB::raw('COUNT(DISTINCT payments.order_sale_id) as pedidos'),
                DB::raw('SUM(payments.total) as total_ingresos')
            )
            ->join('OrderSale', 'OrderSale.order_sale_id', '=', 'payments.order_sale_id')
            ->join('business', 'business.busines_id', '=', 'OrderSale.busines_id')
            ->when(
                $this->business_id,
                fn($q) =>
                $q->where('OrderSale.busines_id', $this->business_id)
            )
            ->whereBetween('payments.payment_date', [$this->date_start, $this->date_end])
            ->groupBy('mes', 'business.name')
            ->orderBy('mes')
            ->get();
    }

    public function title(): string
    {
        return 'Ingresos negocio (mensual)';
    }

    public function styles(Worksheet $sheet)
    {
        $sheet->getStyle('A1:D1')->getFont()->setBold(true);
        $sheet->setAutoFilter('A1:D1');
        foreach (range('A', 'D') as $col) {
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }
    }
}
