<?php

namespace App\Exports\Comercials;

use App\Models\Product\Category;
use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithTitle;
use Maatwebsite\Excel\Concerns\WithStyles;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class TopCategoriesSheet implements FromCollection, WithHeadings, WithTitle, WithStyles
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
        return Category::select('category.name', DB::raw('COUNT(orderssales_detail.orderDet_id) as total_items'))
            ->join('products', 'products.category_id', '=', 'category.category_id')
            ->join('orderssales_detail', 'orderssales_detail.product_id', '=', 'products.products_id')
            ->join('orderssales', 'orderssales.orderSales_id', '=', 'orderssales_detail.orderSales_id')
            ->whereBetween('orderssales.sale_date', [$this->start, $this->end])
            ->groupBy('category.name')
            ->orderByDesc('total_items')
            ->get();
    }

    public function headings(): array
    {
        return ['Categoría', 'Items Vendidos'];
    }

    public function title(): string
    {
        return 'Top Categorías';
    }

    public function styles(Worksheet $sheet)
    {
        $sheet->getStyle('A1:B1')->getFont()->setBold(true);
        $sheet->setAutoFilter($sheet->calculateWorksheetDimension());
        return [];
    }
}
