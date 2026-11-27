<?php

declare(strict_types=1);

namespace App\Models\Conjunto;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * La pregunta del celador al residente: «¿dejo entrar a esta persona?».
 *
 * Ver la migración `autorizaciones_de_entrada` y `AutorizacionDelResidente`.
 *
 * @property int $id
 * @property int $complex_id
 * @property string $tower
 * @property string $apartment
 * @property string $visitor_name
 * @property string $kind
 * @property string $state
 * @property int $residents_notified
 * @property int|null $requested_by
 * @property int|null $answered_by
 * @property Carbon|null $answered_at
 * @property Carbon $expires_at
 */
class SolicitudDeEntrada extends Model
{
    public const PENDIENTE = 'pendiente';

    public const AUTORIZADA = 'autorizada';

    public const RECHAZADA = 'rechazada';

    public const VENCIDA = 'vencida';

    /** Cinco minutos: lo que una visita espera razonablemente en la puerta. */
    public const VIGENCIA_MINUTOS = 5;

    protected $table = 'complex_entry_requests';

    protected $fillable = [
        'complex_id', 'tower', 'apartment', 'visitor_name', 'kind', 'state',
        'residents_notified', 'requested_by', 'answered_by', 'answered_at', 'expires_at',
    ];

    protected $casts = [
        'answered_at' => 'datetime',
        'expires_at' => 'datetime',
        'residents_notified' => 'integer',
    ];

    /**
     * El estado de verdad: una pendiente cuyo plazo pasó ya está vencida
     * aunque nadie la haya marcado. Se marca al leerla, así no hace falta una
     * tarea programada para eso.
     */
    public function estadoVigente(): string
    {
        if ($this->state === self::PENDIENTE && $this->expires_at->isPast()) {
            $this->forceFill(['state' => self::VENCIDA])->save();
        }

        return $this->state;
    }
}
