<?php

declare(strict_types=1);

namespace App\Exports\operational;

use App\Exports\Compartido\SinFormulas;
use App\Models\Reviews\BusinessReview;
use App\Models\Reviews\DomiciliaryReview;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithTitle;

class SatisfaccionSheet extends SinFormulas implements FromArray, WithTitle
{
    public function __construct(protected $start, protected $end) {}

    public function array(): array
    {
        $businessReviews = BusinessReview::whereBetween('created_at', [$this->start, $this->end])->avg('qualification');
        $domiciliaryReviews = DomiciliaryReview::whereBetween('created_at', [$this->start, $this->end])->avg('qualification');

        return [
            ['Tipo', 'Calificación Promedio'],
            ['Negocios', round($businessReviews ?? 0, 2)],
            ['Domiciliarios', round($domiciliaryReviews ?? 0, 2)],
        ];
    }

    public function title(): string
    {
        return 'Satisfaccion';
    }
}
