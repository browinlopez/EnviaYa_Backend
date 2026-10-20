<?php

namespace App\Models\Order;

use App\Models\Audit\Audit;
use App\Models\Product\Product;
use Illuminate\Database\Eloquent\Model;

class OrdersSalesDetail extends Audit
{
    protected $table = 'orderssales_detail';
    protected $primaryKey = 'orderDet_id';
    public $timestamps = false;

    /*
     * `amount` SIGUE SIENDO ENTERO, y en una línea por peso vale 1.
     *
     * Lo que se vendió de verdad está en `qty_requested` / `qty_final`, y lo
     * que se cobra en `line_total`. Migrar `amount` a decimal habría cambiado
     * el significado de veintidós sitios en silencio —el descuento de
     * inventario, lo que viaja a la pasarela, el comprobante, el ranking de
     * destacados—, así que el peso entra por columnas nuevas y el código que
     * no se enteró sigue siendo correcto, porque solo opera sobre productos
     * cuya unidad es `unidad`.
     *
     * Para leer el importe de una línea hay UNA regla y vive en
     * `App\Support\ImporteDeLinea`:  line_total ?? unit_price * amount.
     */
    protected $fillable = [
        'orderSales_id', 'product_id', 'amount', 'unit_price',
        'sale_unit', 'pedido_por', 'qty_requested', 'qty_final',
        'line_total', 'weighed_at', 'weighed_by',
    ];

    protected $casts = [
        'unit_price'    => 'decimal:2',
        'qty_requested' => 'decimal:3',
        'qty_final'     => 'decimal:3',
        'line_total'    => 'decimal:2',
        'weighed_at'    => 'datetime',
    ];

    public function order()
    {
        return $this->belongsTo(OrdersSales::class, 'orderSales_id', 'orderSales_id');
    }

    public function product()
    {
        return $this->belongsTo(Product::class, 'product_id', 'products_id');
    }
}
