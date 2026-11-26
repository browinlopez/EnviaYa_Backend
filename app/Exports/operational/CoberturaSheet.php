<?php

declare(strict_types=1);

namespace App\Exports\operational;

use App\Exports\Compartido\SinFormulas;
use App\Models\Order\OrdersSales;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithTitle;

class CoberturaSheet extends SinFormulas implements FromCollection, WithHeadings, WithTitle
{
    public function __construct(protected $start, protected $end) {}

    public function collection()
    {
        return OrdersSales::with('address.municipality')
            ->whereBetween('sale_date', [$this->start, $this->end])
            ->get()
            ->map(fn ($o) => [
                'Pedido' => $o->orderSales_id,
                'Latitud' => $o->address?->latitude,
                'Longitud' => $o->address?->longitude,
                'Municipio' => $o->address?->municipality?->name,
            ]);
    }

    public function headings(): array
    {
        return ['Pedido', 'Latitud', 'Longitud', 'Municipio'];
    }

    public function title(): string
    {
        return 'Cobertura';
    }
}
