<?php

namespace App\Models\Operacion;

use App\Models\Audit\Audit;
use App\Models\Domiciliary;
use App\Models\User;

/**
 * Un depósito de efectivo declarado por un domiciliario.
 *
 * Declarar no es entregar: la fila nace `pendiente` y no toca el saldo. Solo
 * al confirmarla desde el panel se apunta el movimiento en el libro. Si el
 * depósito bajara el saldo al declararlo, cualquiera saldaría su deuda
 * escribiendo un número de referencia inventado.
 */
class CashDeposit extends Audit
{
    protected $table = 'cash_deposits';

    public const PENDIENTE  = 'pendiente';
    public const CONFIRMADA = 'confirmada';
    public const RECHAZADA  = 'rechazada';

    public const ESTADOS = [self::PENDIENTE, self::CONFIRMADA, self::RECHAZADA];

    protected $fillable = [
        'domiciliary_id',
        'amount',
        'reference',
        'deposited_at',
        'receipt_path',
        'state',
        'confirmed_by',
        'confirmed_at',
        'notes',
    ];

    protected $casts = [
        'amount'       => 'decimal:2',
        'deposited_at' => 'datetime',
        'confirmed_at' => 'datetime',
    ];

    public function domiciliary()
    {
        return $this->belongsTo(Domiciliary::class, 'domiciliary_id', 'domiciliary_id');
    }

    public function confirmadoPor()
    {
        return $this->belongsTo(User::class, 'confirmed_by', 'user_id');
    }
}
