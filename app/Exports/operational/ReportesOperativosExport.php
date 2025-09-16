<?php

namespace App\Exports\operational;

use Maatwebsite\Excel\Concerns\WithMultipleSheets;

class ReportesOperativosExport implements WithMultipleSheets
{
    public function __construct(protected $start, protected $end) {}

    public function sheets(): array
    {
        return [
            new ResumenOperativoSheet($this->start, $this->end),
            new CoberturaSheet($this->start, $this->end),
            new CancelacionesSheet($this->start, $this->end),
            new SatisfaccionSheet($this->start, $this->end),
             new DisponibilidadDomiciliariosSheet($this->start, $this->end),
        ];
    }
}
