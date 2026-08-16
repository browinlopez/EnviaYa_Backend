<?php

namespace App\Models\Operacion;

use App\Models\Domiciliary;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Documento de un domiciliario, con su vigencia.
 */
class DomiciliaryDocument extends Model
{
    protected $table = 'domiciliary_documents';

    /** Días antes del vencimiento en que empieza a avisarse. */
    public const AVISO_DIAS = 30;

    /** Los que la ley exige para repartir en moto. Sin ellos no debería rodar. */
    public const OBLIGATORIOS = ['licencia', 'soat', 'tecnomecanica', 'arl', 'eps'];

    protected $fillable = [
        'domiciliary_id',
        'type',
        'number',
        'issued_at',
        'expires_at',
        'media_id',
        'notes',
        'state',
    ];

    protected $casts = [
        'domiciliary_id' => 'integer',
        'issued_at'      => 'date',
        'expires_at'     => 'date',
        'media_id'       => 'integer',
        'state'          => 'integer',
    ];

    /**
     * El valor por defecto se repite acá y no solo en la migración.
     *
     * La base pone 1, pero un modelo recién creado sin pasar `state` lo tiene
     * en null hasta que se recargue, y `situacion()` lo leía como archivado:
     * un documento acabado de registrar aparecía fuera de circulación.
     */
    protected $attributes = [
        'state' => 1,
    ];

    public function domiciliary(): BelongsTo
    {
        return $this->belongsTo(Domiciliary::class, 'domiciliary_id', 'domiciliary_id');
    }

    /**
     * En qué situación está el documento hoy.
     *
     * Se calcula y no se guarda: un estado persistido queda obsoleto solo con
     * que pase el tiempo, y habría que recorrer la tabla cada noche para
     * mantenerlo al día. Acá siempre dice la verdad del momento en que se mira.
     */
    public function situacion(): string
    {
        if ((int) $this->state !== 1) {
            return 'archivado';
        }

        if (!$this->expires_at) {
            return 'sin_vencimiento';
        }

        $dias = now()->startOfDay()->diffInDays($this->expires_at, false);

        if ($dias < 0) {
            return 'vencido';
        }

        return $dias <= self::AVISO_DIAS ? 'por_vencer' : 'vigente';
    }

    /** Días que faltan (negativo si ya venció). */
    public function diasRestantes(): ?int
    {
        if (!$this->expires_at) {
            return null;
        }

        return (int) now()->startOfDay()->diffInDays($this->expires_at, false);
    }

    /** Vencidos o a punto de vencer: lo que el tablero de SST pone primero. */
    public function scopeRequiereAtencion(Builder $q): Builder
    {
        return $q->where($q->qualifyColumn('state'), 1)
            ->whereNotNull($q->qualifyColumn('expires_at'))
            ->whereDate($q->qualifyColumn('expires_at'), '<=', now()->addDays(self::AVISO_DIAS)->toDateString());
    }
}
