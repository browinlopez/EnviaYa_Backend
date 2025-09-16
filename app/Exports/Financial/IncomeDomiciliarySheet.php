<?php

namespace App\Exports\Financial;

use App\Models\Payment\Payment;
use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithTitle;
use Maatwebsite\Excel\Concerns\WithHeadings;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use Maatwebsite\Excel\Concerns\WithStyles;

class IncomeDomiciliarySheet implements FromCollection, WithStyles, WithTitle, WithHeadings
{
    protected $domiciliary_id, $date_start, $date_end;

    public function __construct($domiciliary_id, $date_start, $date_end)
    {
        $this->domiciliary_id = $domiciliary_id;
        $this->date_start     = $date_start;
        $this->date_end       = $date_end;
    }

    public function collection()
    {
        return Payment::query()
            ->select(
                DB::raw("DATE_FORMAT(payments.payment_date,'%Y-%m') as mes"),
                'u.name as domiciliario',
                DB::raw('COUNT(DISTINCT payments.orderSales_id) as pedidos'),
                DB::raw('SUM(payments.domicilio) as total_domiciliario')
            )
            ->join('orderssales','orderssales.orderSales_id','=','payments.orderSales_id')
            ->join('domiciliary','domiciliary.domiciliary_id','=','orderssales.domiciliary_id')
            ->join('user as u','u.user_id','=','domiciliary.user_id')
            ->when($this->domiciliary_id, fn($q)=>
                $q->where('orderssales.domiciliary_id',$this->domiciliary_id)
            )
            ->whereBetween('payments.payment_date',[$this->date_start,$this->date_end])
            ->groupBy('mes','u.name')
            ->orderBy('mes')
            ->get();
    }

    public function title(): string
    {
        return 'Pagos Domiciliarios';
    }

    public function headings(): array
    {
        return ['Mes','Domiciliario','Pedidos','Total pagado'];
    }

     public function styles(Worksheet $sheet)
    {
        $sheet->getStyle('A1:D1')->getFont()->setBold(true);
        $sheet->setAutoFilter('A1:D1');
        foreach (range('A','D') as $col) {
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }
    }
}
