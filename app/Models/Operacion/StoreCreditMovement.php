<?php

declare(strict_types=1);

namespace App\Models\Operacion;

use App\Models\Order\OrdersSales;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Un apunte del libro de crédito. El signo es lo que hace a lo USADO:
 * consumo suma, reverso y abono restan, ajuste va con el signo que traiga.
 */
class StoreCreditMovement extends Model
{
    protected $table = 'store_credit_movements';

    public const CONSUMO = 'consumo';

    public const REVERSO = 'reverso';

    public const ABONO = 'abono';

    /**
     * HOY NO LO ESCRIBE NADIE, igual que su gemelo en `CashMovement`.
     *
     * Se queda por lo mismo: el saldo del credito es `sum('amount')` sobre
     * TODOS los tipos --el signo lleva el significado-- asi que una fila
     * de ajuste cuenta en el saldo en cuanto exista. Lo que el tipo decide
     * es la IDEMPOTENCIA: `reversar` busca por tipo si ya se devolvio. Si
     * este valor colisionara con `CONSUMO` o `REVERSO`, cancelar un pedido
     * a credito dejaria de devolver el cupo.
     */
    public const AJUSTE = 'ajuste';

    protected $fillable = ['store_credit_id', 'type', 'amount', 'order_id', 'notes', 'comprobante_media_id', 'created_by'];

    protected $casts = ['amount' => 'decimal:2'];

    /** @return BelongsTo<StoreCredit, $this> */
    public function credit(): BelongsTo
    {
        return $this->belongsTo(StoreCredit::class, 'store_credit_id');
    }

    /** @return BelongsTo<OrdersSales, $this> */
    public function order(): BelongsTo
    {
        return $this->belongsTo(OrdersSales::class, 'order_id', 'orderSales_id');
    }
}
