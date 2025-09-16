<?php

namespace App\Exports\Comercials;

use Maatwebsite\Excel\Concerns\WithMultipleSheets;

class ReportGeneralComercial implements WithMultipleSheets
{
    protected $start;
    protected $end;

    public function __construct($start, $end)
    {
        $this->start = $start;
        $this->end   = $end;
    }

    public function sheets(): array
    {
        return [
            new ResumenSheet($this->start, $this->end),
            new OrdersSheet($this->start, $this->end),
            new UsersSheet($this->start, $this->end),
            new TopBusinessesSheet($this->start, $this->end),
            new TopCategoriesSheet($this->start, $this->end)
        ];
    }
}
