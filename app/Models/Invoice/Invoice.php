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
        'payments_id',
        'payment_provider',
        'payment_reference',
        'invoice_number',
        'invoice_date',
        'subtotal',
        'descuento',
        'iva',
        'total',
        'currency',
        'notes',
    ];

    protected $casts = [
        'invoice_date' => 'datetime',
        'subtotal' => 'decimal:2',
        'descuento' => 'decimal:2',
        'iva' => 'decimal:2',
        'total' => 'decimal:2',
    ];

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
