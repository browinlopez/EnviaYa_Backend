<?php

namespace App\Models\Payment;

use App\Models\Audit\Audit;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class PaymentEvent extends Audit
{
    use HasFactory;

    protected $table = 'payment_events';

    protected $fillable = [
        'provider',
        /*
         * La llave con la que se descarta un aviso repetido. La pone el
         * controlador: el `id` de Bold si viene, y si no el hash del cuerpo.
         * Tiene índice ÚNICO, así que descartar el duplicado no depende de una
         * comprobación previa sino de la base.
         */
        'notification_id',
        'event_type',
        'reference_id',
        'payload',
        'received_at',
    ];

    protected $casts = [
        'payload' => 'array',
    ];
}
