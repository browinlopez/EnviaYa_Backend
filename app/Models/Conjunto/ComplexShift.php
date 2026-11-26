<?php

declare(strict_types=1);

namespace App\Models\Conjunto;

use App\Models\Audit\Audit;
use App\Models\User;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Un turno de un celador en la portería de su conjunto.
 *
 * Abierto mientras `ended_at` sea null; uno abierto por celador como mucho.
 * Auditado: una hora de fin que alguien corrige es justo lo que una auditoría
 * tiene que poder contar.
 *
 * Sin cast de fecha, como `ComplexEntry`: las respuestas del panel entregan
 * las fechas como texto `Y-m-d H:i:s`.
 */
class ComplexShift extends Audit
{
    protected $table = 'complex_shifts';

    protected $fillable = ['complex_id', 'user_id', 'started_at', 'ended_at'];

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id', 'user_id');
    }

    /** El turno abierto de esa persona, o null. */
    public static function abiertoDe(int $userId): ?self
    {
        return self::where('user_id', $userId)->whereNull('ended_at')->latest('started_at')->first();
    }

    /** Cierra el turno abierto de esa persona, si lo tiene. Devuelve el turno cerrado. */
    public static function cerrarDe(int $userId): ?self
    {
        $turno = self::abiertoDe($userId);
        $turno?->update(['ended_at' => now()->toDateTimeString()]);

        return $turno;
    }
}
