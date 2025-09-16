<?php

namespace App\Exports\operational;

use App\Models\Order\OrdersSales;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithTitle;

class CancelacionesSheet implements FromCollection, WithHeadings, WithTitle
{
    public function __construct(protected $start, protected $end) {}

    public function collection()
    {
        return OrdersSales::with(['buyer.user', 'business', 'domiciliary.user'])
            ->whereBetween('sale_date', [$this->start, $this->end])
            ->where('state', 'cancelado') // ajusta al estado real
            ->get()
            ->map(fn($o) => [
                'Pedido' => $o->orderSales_id,
                'Cliente'=> $o->buyer?->user?->name ?? 'N/A',
                'Negocio'=> $o->business?->name ?? 'N/A',
                'Domiciliario' => $o->domiciliary?->user?->name ?? 'N/A',
                'Fecha' => $o->sale_date,
            ]);
    }

    public function headings(): array
    {
        return ['Pedido','Cliente','Negocio','Domiciliario','Fecha'];
    }

    public function title(): string
    {
        return 'Cancelaciones';
    }
}
