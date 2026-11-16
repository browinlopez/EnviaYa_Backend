<?php

declare(strict_types=1);

namespace App\Models\Payment;

use App\Models\Audit\Audit;
use App\Models\Order\OrdersSales;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Payment extends Audit
{
    use HasFactory;

    protected $table = 'payments';

    protected $primaryKey = 'payments_id';

    public $timestamps = true;

    /**
     * LOS VALORES DE `status` QUE SIGNIFICAN QUE LA PLATA ENTRÓ.
     *
     * SON DOS PERO SOLO UNO EXISTE. Medido el 14 de noviembre de 2026 contra
     * la base: 187 filas cobradas, TODAS con `approved`, tanto las de Bold
     * como las de efectivo. `paid` es el vocabulario de OTRA columna
     * --`orderssales.payment_state`, que sí lo escribe en tres servicios--
     * y se colló aquí al escribir la condición.
     *
     * Se queda igualmente: `whereIn` con un valor que nunca aparece da
     * exactamente el mismo resultado que el `where` de un solo valor, así que
     * quitarlo no compra nada y sería tocar la condición de la que cuelga el
     * tablero. Lo que no se queda es la duda: está medido.
     *
     * Estaba como `private const PAGO_OK` en el rasgo `AyudasDeAdmin` --y por
     * tanto repetida en sus diez consumidores-- más un literal suelto en
     * `FacturaService`. Va en el modelo de pagos porque describe una columna
     * de `payments`, que es lo que §07 dice que hace un modelo.
     */
    public const COBRADOS = ['approved', 'paid'];

    protected $fillable = [
        'orderSales_id',
        'methods_id',
        'forms_id',
        'provider',
        'provider_payment_id',
        'amount',
        'subtotal',
        'total',
        'domicilio',
        // Porción del domicilio que cobra el domiciliario; de esta columna
        // salen sus reportes de ingresos.
        'domiciliary_fee',
        'valor_promocion',
        'payment_status',
        'status',
        'provider_snapshot',
        'payment_date',
        'redirect_url',
        'qr_payload',
        'qr_expires_at',
        /* La devolucion vive junto al cobro: es la misma plata. */
        'refund_status',
        'refund_reason',
        'refunded_at',
        'refund_snapshot',
        'state',
    ];

    protected $casts = [
        'provider_snapshot' => 'array',
        'refund_snapshot' => 'array',
        'refunded_at' => 'datetime',
        'payment_date' => 'datetime',
        /* La caducidad del QR es una fecha como las otras dos, y era la unica
           que salia como texto crudo: cualquier cuenta con ella habia que
           hacerla a mano, y es el reloj que ve quien esta pagando. */
        'qr_expires_at' => 'datetime',
    ];

    /** @return BelongsTo<OrdersSales, $this> */
    public function order(): BelongsTo
    {
        return $this->belongsTo(OrdersSales::class, 'orderSales_id');
    }

    /** @return HasOne<PaymentIntent, $this> */
    public function intent(): HasOne
    {
        return $this->hasOne(PaymentIntent::class, 'orderSales_id', 'orderSales_id');
    }

    /** @return BelongsTo<PaymentMethods, $this> */
    public function method(): BelongsTo
    {
        return $this->belongsTo(PaymentMethods::class, 'methods_id', 'methods_id');
    }

    /** @return BelongsTo<PaymentForms, $this> */
    public function form(): BelongsTo
    {
        return $this->belongsTo(PaymentForms::class, 'forms_id', 'forms_id');
    }
}
