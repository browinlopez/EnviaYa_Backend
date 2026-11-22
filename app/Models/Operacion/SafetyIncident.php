<?php

declare(strict_types=1);

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

    public const ABIERTO = 0;

    public const EN_INVESTIGACION = 1;

    public const CERRADO = 2;

    /** El vocabulario completo: lo pide `IncidenteRequest`, que antes
     * escribia `in:0,1,2` a mano. */
    public const ESTADOS = [self::ABIERTO, self::EN_INVESTIGACION, self::CERRADO];

    /**
     * Los motivos que el botón de SOS sabe mandar.
     *
     * Es un subconjunto del enum a propósito: el enum completo incluye cosas
     * que sólo tienen sentido escritas con calma desde el panel —«condición
     * insegura», «falla del vehículo»— y la hoja del teléfono no las ofrece.
     * Aceptar aquí todo el vocabulario dejaría que un cliente mandara
     * cualquiera de ellos con gravedad «grave», que es como entra un SOS.
     */
    public const MOTIVOS_SOS = ['robo', 'extorsion', 'accidente_transito', 'otro'];

    protected $fillable = [
        'domiciliary_id',
        'business_id',
        'order_id',
        'occurred_at',
        'type',
        'severity',
        'had_injuries',
        'days_off',
        'location',
        'latitude',
        'longitude',
        'description',
        'actions',
        'state',
        'reported_by',
    ];

    protected $casts = [
        'domiciliary_id' => 'integer',
        'business_id' => 'integer',
        'order_id' => 'integer',
        'occurred_at' => 'datetime',
        'latitude' => 'float',
        'longitude' => 'float',
        'had_injuries' => 'boolean',
        'days_off' => 'integer',
        'state' => 'integer',
        'closed_at' => 'datetime',
        'reported_by' => 'integer',
    ];

    /** @return BelongsTo<Domiciliary, $this> */
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
