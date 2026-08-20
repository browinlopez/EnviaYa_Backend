<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * El token de un teléfono concreto, para poder mandarle una notificación.
 *
 * Lo registra la app al iniciar sesión y cada vez que el proveedor se lo renueva
 * —FCM los rota—, así que el registro es idempotente: mismo token, misma fila.
 */
class DeviceToken extends Model
{
    protected $table = 'device_tokens';

    protected $fillable = [
        'user_id',
        'token',
        'platform',
        'app_version',
        'device_name',
        'last_seen_at',
        'failed_at',
        'fail_reason',
    ];

    protected $casts = [
        'last_seen_at' => 'datetime',
        'failed_at'    => 'datetime',
    ];

    /** Los que todavía sirven. Un token marcado como fallido no se reintenta. */
    public function scopeVivos($q)
    {
        return $q->whereNull('failed_at');
    }
}
