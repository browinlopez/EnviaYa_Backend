<?php

namespace App\Models\Operacion;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Movimiento en la bitácora de un PQRS.
 *
 * Sin `updated_at`: lo que se registró en un seguimiento no se edita. Poder
 * reescribirlo destruiría el valor de la bitácora, que es precisamente servir
 * de constancia cuando el cliente reclama que nadie lo atendió.
 */
class PqrsNote extends Model
{
    protected $table = 'pqrs_notes';

    public const UPDATED_AT = null;

    protected $fillable = ['pqrs_id', 'user_id', 'note', 'is_internal'];

    protected $casts = [
        'pqrs_id'     => 'integer',
        'user_id'     => 'integer',
        'is_internal' => 'boolean',
    ];

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id', 'user_id');
    }
}
