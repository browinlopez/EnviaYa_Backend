<?php

namespace App\Models\Operacion;

use App\Models\Domiciliary;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Incidente o accidente durante la operación.
 */
class SafetyIncident extends Model
{
    protected $table = 'safety_incidents';

    public const ABIERTO       = 0;
    public const EN_INVESTIGACION = 1;
    public const CERRADO       = 2;

    protected $fillable = [
        'domiciliary_id',
        'order_id',
        'occurred_at',
        'type',
        'severity',
        'had_injuries',
        'days_off',
        'location',
        'description',
        'actions',
        'state',
        'reported_by',
    ];

    protected $casts = [
        'domiciliary_id' => 'integer',
        'order_id'       => 'integer',
        'occurred_at'    => 'datetime',
        'had_injuries'   => 'boolean',
        'days_off'       => 'integer',
        'state'          => 'integer',
        'closed_at'      => 'datetime',
        'reported_by'    => 'integer',
    ];

    public function domiciliary(): BelongsTo
    {
        return $this->belongsTo(Domiciliary::class, 'domiciliary_id', 'domiciliary_id');
    }

    /**
     * Un incidente no se cierra sin decir qué se hizo.
     *
     * Cerrar sin acciones deja un registro que solo sirve para contar cuántos
     * hubo, y el punto del módulo es que no se repitan.
     */
    public function puedeCerrarse(): bool
    {
        return trim((string) $this->actions) !== '';
    }
}
