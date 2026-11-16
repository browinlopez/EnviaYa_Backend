<?php

declare(strict_types=1);

namespace App\Models\Conjunto;

use App\Models\Audit\Audit;
use App\Models\Buyer\ResidentialComplex;
use App\Models\User;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * El vínculo entre una persona y SU conjunto.
 *
 * Es lo que convierte un permiso por módulo en un permiso por registro. Todo
 * endpoint del panel de aliados saca el conjunto de acá, tomado de la sesión, y
 * NUNCA de un parámetro de la petición: con un identificador en la URL,
 * cualquier dueño podría mirar el conjunto del vecino cambiando un número.
 */
class ComplexStaff extends Audit
{
    protected $table = 'complex_staff';

    public const DUENO = 'dueno';

    public const CELADOR = 'celador';

    /** Los identificadores de la tabla `rol`, que no es autoincremental. */
    public const ROL_DUENO = 5;

    public const ROL_CELADOR = 6;

    /** Los dos roles que pueden entrar por la puerta del conjunto. */
    public const ROLES_DE_USUARIO = [self::ROL_DUENO, self::ROL_CELADOR];

    protected $fillable = [
        'user_id',
        'complex_id',
        'role',
        'state',
        'created_by',
    ];

    protected $casts = ['state' => 'boolean'];

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id', 'user_id');
    }

    /** @return BelongsTo<ResidentialComplex, $this> */
    public function complex(): BelongsTo
    {
        return $this->belongsTo(ResidentialComplex::class, 'complex_id', 'complex_id');
    }

    /** La ficha activa de quien pide, o null si no es personal de conjunto. */
    public static function de(?int $userId): ?self
    {
        if (! $userId) {
            return null;
        }

        return self::where('user_id', $userId)->where('state', true)->first();
    }

    public function esDueno(): bool
    {
        return $this->role === self::DUENO;
    }
}
