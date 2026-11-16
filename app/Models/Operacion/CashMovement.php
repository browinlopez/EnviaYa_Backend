<?php

declare(strict_types=1);

namespace App\Models\Operacion;

use App\Models\Domiciliary;
use App\Models\Order\OrdersSales;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Un apunte del libro de efectivo de un domiciliario.
 *
 * El signo lo dice todo y por eso hay un solo campo de importe:
 *
 *   positivo  debe más (recaudó un pedido en efectivo)
 *   negativo  debe menos (consignó, o se le ajustó a favor)
 *
 * El saldo es la suma. No hay columna de saldo a propósito: un saldo sin
 * movimientos detrás no se puede auditar, y el día que no cuadre —que va a
 * pasar— hay que poder ver de dónde salió cada peso.
 */
class CashMovement extends Model
{
    protected $table = 'cash_movements';

    public const RECAUDO = 'recaudo';

    public const CONSIGNACION = 'consignacion';

    /**
     * HOY NO LO ESCRIBE NADIE, y aun asi importa.
     *
     * No hay endpoint para ajustar a mano un descuadre: la funcion no
     * esta hecha. Pero el valor vive en `cash_movements.type`, y
     * `pendientesConLaTienda()` filtra por `type = RECAUDO`, asi que lo
     * que decide esta constante es que un ajuste NO se cuente como plata
     * recaudada. El dia que se cambiara a 'recaudo' se le cobraria al
     * domiciliario un descuadre perdonado.
     *
     * Por eso `CustodiaDeEfectivoTest` la fija con su literal y comprueba
     * que los tres tipos sean distintos: es lo unico que el resto del
     * codigo necesita de ellos.
     */
    public const AJUSTE = 'ajuste';

    protected $fillable = [
        'domiciliary_id',
        'type',
        'amount',
        'order_id',
        'deposit_id',
        'notes',
        'created_by',
    ];

    protected $casts = ['amount' => 'decimal:2'];

    /** @return BelongsTo<Domiciliary, $this> */
    public function domiciliary(): BelongsTo
    {
        return $this->belongsTo(Domiciliary::class, 'domiciliary_id', 'domiciliary_id');
    }

    /** @return BelongsTo<OrdersSales, $this> */
    public function order(): BelongsTo
    {
        return $this->belongsTo(OrdersSales::class, 'order_id', 'orderSales_id');
    }

    /** @return BelongsTo<CashDeposit, $this> */
    public function deposit(): BelongsTo
    {
        return $this->belongsTo(CashDeposit::class, 'deposit_id');
    }
}
