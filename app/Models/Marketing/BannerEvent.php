<?php

namespace App\Models\Marketing;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Impresión o clic sobre un banner.
 *
 * Sin `updated_at`: un evento ocurrió una vez y no se modifica. Dejar la
 * columna sería reservar espacio en la tabla que más crece del módulo para un
 * dato que nunca cambia.
 */
class BannerEvent extends Model
{
    protected $table = 'banner_events';

    public const UPDATED_AT = null;

    protected $fillable = [
        'banner_id',
        'type',
        'user_id',
        'platform',
        'ip',
        'day',
    ];

    protected $casts = [
        'banner_id' => 'integer',
        'user_id'   => 'integer',
        'day'       => 'date',
    ];

    public function banner(): BelongsTo
    {
        return $this->belongsTo(Banner::class, 'banner_id');
    }
}
