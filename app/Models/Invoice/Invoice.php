<?php

namespace App\Models\Invoice;

use App\Models\Audit\Audit;
use App\Models\Order\OrdersSales;
use App\Models\Payment\Payment;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Invoice extends Audit
{
    use HasFactory;

    protected $table = 'invoices';
    protected $primaryKey = 'invoice_id';

    protected $fillable = [
        'orderSales_id',
        'busines_id',
        'buyer_id',
        'payments_id',
        'payment_provider',
        'payment_reference',
        'invoice_number',
        'invoice_date',
        'subtotal',
        'descuento',
        'iva',
        'domicilio',
        'domiciliary_fee',
        'total',
        'currency',
        'snapshot',
        'state',
        'notes',
    ];

    protected $casts = [
        'invoice_date' => 'datetime',
        'voided_at' => 'datetime',
        'subtotal' => 'decimal:2',
        'descuento' => 'decimal:2',
        'domicilio' => 'decimal:2',
        'domiciliary_fee' => 'decimal:2',
        'iva' => 'decimal:2',
        'total' => 'decimal:2',
        // La foto de los datos al emitir. Va como arreglo y no como texto para
        // que la plantilla no tenga que decodificarla en cada renglón.
        'snapshot' => 'array',
    ];

    /*
     * `void_reason`, `voided_at` y `voided_by` NO son asignables en masa a
     * propósito: anular es una operación con consecuencia y pasa por
     * `FacturaService::anular()`, que además comprueba que no esté ya anulada.
     * Dejarlas en `$fillable` permitiría anular una factura desde cualquier
     * `update()` que reciba esos campos.
     */

    public function estaAnulada(): bool
    {
        return (int) $this->state === 0;
    }

    /**
     * Invoice belongs to an order.
     */
    public function order()
    {
        return $this->belongsTo(OrdersSales::class, 'orderSales_id', 'orderSales_id');
    }

    /**
     * Invoice belongs to a payment (if invoice generated after payment).
     */
    public function payment()
    {
        return $this->belongsTo(Payment::class, 'payments_id', 'payments_id');
    }
}
