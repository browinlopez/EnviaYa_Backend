<?php

declare(strict_types=1);

namespace App\Models\Negocio;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Una línea de una venta de mostrador: «$1.500 de queso», «3 Pony Malta». */
class LineaDeVentaDeMostrador extends Model
{
    protected $table = 'ventas_mostrador_lineas';

    protected $primaryKey = 'linea_id';

    public $timestamps = false;

    protected $fillable = [
        'venta_id',
        'products_id',
        'nombre',
        'sale_unit',
        'pedido_por',
        'cantidad',
        'unit_price',
        'unit_cost',
        'line_total',
    ];

    protected $casts = [
        'cantidad' => 'decimal:3',
        'unit_price' => 'decimal:2',
        'unit_cost' => 'decimal:2',
        'line_total' => 'decimal:2',
    ];

    /** @return BelongsTo<VentaDeMostrador, $this> */
    public function venta(): BelongsTo
    {
        return $this->belongsTo(VentaDeMostrador::class, 'venta_id', 'venta_id');
    }
}
