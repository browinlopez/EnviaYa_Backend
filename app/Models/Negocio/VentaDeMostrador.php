<?php

declare(strict_types=1);

namespace App\Models\Negocio;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Una venta hecha en persona, en el mostrador. Ver la migración
 * `2026_11_24_700000_mostrador_y_productos_propios`.
 */
class VentaDeMostrador extends Model
{
    protected $table = 'ventas_mostrador';

    protected $primaryKey = 'venta_id';

    protected $fillable = [
        'busines_id',
        'user_id',
        'total',
        'forma_de_pago',
        'cash_tendered',
        'change_given',
        'vendida_at',
        'estado_pago',
        'bold_referencia',
        'bold_transaccion',
        'qr_payload',
        'qr_expira_at',
        'comision_pasarela',
        'settlement_id',
    ];

    protected $casts = [
        'total' => 'decimal:2',
        'cash_tendered' => 'decimal:2',
        'change_given' => 'decimal:2',
        'vendida_at' => 'datetime',
        'qr_expira_at' => 'datetime',
        'comision_pasarela' => 'decimal:2',
    ];

    /** @return HasMany<LineaDeVentaDeMostrador, $this> */
    public function lineas(): HasMany
    {
        return $this->hasMany(LineaDeVentaDeMostrador::class, 'venta_id', 'venta_id');
    }
}
