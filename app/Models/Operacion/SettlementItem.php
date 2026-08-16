<?php

namespace App\Models\Operacion;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Un pedido dentro de un corte, con lo que aportó.
 *
 * Sin timestamps: la fila se escribe una vez al generar la liquidación y no
 * vuelve a cambiar. Su fecha relevante es la del pedido y la del corte, no la
 * de esta fila.
 */
class SettlementItem extends Model
{
    protected $table = 'settlement_items';

    public $timestamps = false;

    protected $fillable = [
        'settlement_id',
        'order_id',
        'gross',
        'delivery_fee',
        'discount',
        'net',
    ];

    protected $casts = [
        'settlement_id' => 'integer',
        'order_id'      => 'integer',
        'gross'         => 'decimal:2',
        'delivery_fee'  => 'decimal:2',
        'discount'      => 'decimal:2',
        'net'           => 'decimal:2',
    ];

    public function settlement(): BelongsTo
    {
        return $this->belongsTo(Settlement::class, 'settlement_id');
    }
}
