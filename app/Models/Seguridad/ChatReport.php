<?php

declare(strict_types=1);

namespace App\Models\Seguridad;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * La denuncia de un tendero contra un comprador, hecha desde el chat.
 *
 * Los estados son los mismos tres de `SafetyIncident` para que el equipo no
 * tenga que aprenderse dos vocabularios para dos bandejas parecidas.
 */
class ChatReport extends Model
{
    protected $table = 'chat_reports';

    public const ABIERTO = 0;

    public const EN_REVISION = 1;

    public const CERRADO = 2;

    public const ESTADOS = [self::ABIERTO, self::EN_REVISION, self::CERRADO];

    /**
     * Por qué se denuncia.
     *
     * Son los seis que una tienda de barrio llega a encontrarse, y están
     * separados a propósito: «extorsión» y «amenazas» acaban en sitios muy
     * distintos, y meterlos juntos en «otro» haría imposible contarlos.
     */
    public const MOTIVOS = [
        'extorsion',
        'amenazas',
        'mensajes_ofensivos',
        'estafa',
        'suplantacion',
        'otro',
    ];

    /** Cuántos mensajes se guardan como prueba. */
    public const MENSAJES_DE_PRUEBA = 20;

    protected $fillable = [
        'chat_id',
        'reported_by',
        'reported_user_id',
        'business_id',
        'reason',
        'description',
        'evidence',
        'state',
    ];

    protected $casts = [
        'chat_id' => 'integer',
        'reported_by' => 'integer',
        'reported_user_id' => 'integer',
        'business_id' => 'integer',
        'state' => 'integer',
        'evidence' => 'array',
        'closed_at' => 'datetime',
    ];

    /** @return BelongsTo<User, $this> */
    public function reportado(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reported_user_id', 'user_id');
    }

    /** @return BelongsTo<User, $this> */
    public function denunciante(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reported_by', 'user_id');
    }
}
