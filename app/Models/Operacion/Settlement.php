<?php

namespace App\Models\Operacion;

use App\Models\Business;
use App\Models\Domiciliary;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Corte de cuentas con un negocio o un domiciliario.
 */
class Settlement extends Model
{
    protected $table = 'settlements';

    public const BORRADOR = 0;
    public const APROBADA = 1;
    public const PAGADA   = 2;
    public const ANULADA  = 3;

    protected $fillable = [
        'type',
        'business_id',
        'domiciliary_id',
        'period_start',
        'period_end',
        'orders_count',
        'gross',
        'delivery_fees',
        'discounts',
        'platform_fee',
        'net_payable',
        'state',
        'payment_reference',
        'notes',
        'created_by',
    ];

    protected $casts = [
        'business_id'    => 'integer',
        'domiciliary_id' => 'integer',
        'period_start'   => 'date',
        'period_end'     => 'date',
        'orders_count'   => 'integer',
        'gross'          => 'decimal:2',
        'delivery_fees'  => 'decimal:2',
        'discounts'      => 'decimal:2',
        'platform_fee'   => 'decimal:2',
        'net_payable'    => 'decimal:2',
        'state'          => 'integer',
        'approved_at'    => 'datetime',
        'paid_at'        => 'datetime',
        'created_by'     => 'integer',
    ];

    public function items(): HasMany
    {
        return $this->hasMany(SettlementItem::class, 'settlement_id');
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class, 'business_id', 'busines_id');
    }

    public function domiciliary(): BelongsTo
    {
        return $this->belongsTo(Domiciliary::class, 'domiciliary_id', 'domiciliary_id');
    }

    /**
     * Una liquidación aprobada o pagada ya no se toca.
     *
     * Es el mismo criterio que con una factura emitida: si los montos pudieran
     * cambiar después de aprobarse, el corte dejaría de ser constancia de nada.
     * Para corregir se anula y se genera de nuevo, que además deja rastro.
     */
    public function editable(): bool
    {
        return $this->state === self::BORRADOR;
    }
}
