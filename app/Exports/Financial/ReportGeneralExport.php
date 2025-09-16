<?php

namespace App\Exports\Financial;

use App\Exports\Financial\IncomeBusinessSheet;
use App\Exports\Financial\IncomeDomiciliarySheet;
use App\Exports\Financial\OrdersDetailSheet;
use App\Exports\Financial\PaymentsToStoreSheet;
use App\Exports\Financial\ProfitPerOrderSheet;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use Maatwebsite\Excel\Concerns\WithStyles;

class ReportGeneralExport implements WithMultipleSheets
{
    protected $business_id, $domiciliary_id, $date_start, $date_end;

    public function __construct($business_id, $domiciliary_id, $date_start, $date_end)
    {
        $this->business_id  = $business_id;
        $this->domiciliary_id = $domiciliary_id;
        $this->date_start   = $date_start;
        $this->date_end     = $date_end;
    }

    public function sheets(): array
    {
        return [
            new IncomeDomiciliarySheet($this->domiciliary_id, $this->date_start, $this->date_end),
            new PaymentsToStoreSheet($this->business_id, $this->date_start, $this->date_end),
            new ProfitPerOrderSheet($this->business_id, $this->date_start, $this->date_end),
            new OrdersDetailSheet($this->business_id, $this->domiciliary_id, $this->date_start, $this->date_end), // NUEVA HOJA
        ];
    }
}
