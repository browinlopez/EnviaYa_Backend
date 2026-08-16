<?php

namespace App\Models\Operacion;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

/**
 * Petición, queja, reclamo o sugerencia.
 */
class Pqrs extends Model
{
    protected $table = 'pqrs';

    public const ABIERTO    = 0;
    public const EN_GESTION = 1;
    public const RESUELTO   = 2;
    public const CERRADO    = 3;

    /**
     * Plazo de respuesta en días hábiles aproximados, por prioridad.
     *
     * Se aplican como días corridos a propósito: contar hábiles exigiría un
     * calendario de festivos que la plataforma no tiene, y un plazo que se
     * calcula mal por exceso es peor que uno corto y honesto.
     */
    public const PLAZOS = ['alta' => 2, 'media' => 5, 'baja' => 10];

    protected $fillable = [
        'code',
        'type',
        'channel',
        'priority',
        'user_id',
        'order_id',
        'business_id',
        'domiciliary_id',
        'contact_name',
        'contact_email',
        'contact_phone',
        'subject',
        'description',
        'state',
        'assigned_to',
        'due_at',
        'resolution',
    ];

    protected $casts = [
        'user_id'        => 'integer',
        'order_id'       => 'integer',
        'business_id'    => 'integer',
        'domiciliary_id' => 'integer',
        'state'          => 'integer',
        'assigned_to'    => 'integer',
        'due_at'         => 'datetime',
        'resolved_at'    => 'datetime',
    ];

    public function notes(): HasMany
    {
        return $this->hasMany(PqrsNote::class, 'pqrs_id')->orderByDesc('id');
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to', 'user_id');
    }

    /**
     * Radicado consecutivo por año: PQRS-2026-00042.
     *
     * Lleva el año porque el consecutivo se reinicia y "el 42" sin año deja de
     * identificar nada al segundo año de operación. Se calcula sobre el máximo
     * existente y no sobre el conteo: si se anula un caso, contar daría un
     * número ya usado y el único de la columna lo rechazaría.
     */
    public static function siguienteRadicado(): string
    {
        $anio = now()->year;

        $ultimo = DB::table('pqrs')
            ->where('code', 'like', "PQRS-{$anio}-%")
            ->orderByDesc('code')
            ->value('code');

        $n = $ultimo ? ((int) substr($ultimo, -5)) + 1 : 1;

        return sprintf('PQRS-%d-%05d', $anio, $n);
    }

    /** ¿Se pasó del plazo sin resolverse? */
    public function vencido(): bool
    {
        if (!$this->due_at || in_array($this->state, [self::RESUELTO, self::CERRADO], true)) {
            return false;
        }

        return $this->due_at->isPast();
    }
}
