<?php

declare(strict_types=1);

namespace App\Exports\Financial;

use Maatwebsite\Excel\Concerns\WithMultipleSheets;

class ReportGeneralExport implements WithMultipleSheets
{
    protected $business_id;

    protected $domiciliary_id;

    protected $date_start;

    protected $date_end;

    public function __construct($business_id, $domiciliary_id, $date_start, $date_end)
    {
        $this->business_id = $business_id;
        $this->domiciliary_id = $domiciliary_id;
        $this->date_start = $date_start;
        $this->date_end = $date_end;
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
