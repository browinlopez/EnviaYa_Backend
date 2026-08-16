<?php

namespace App\Models\Marketing;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Campaña: el trato comercial que agrupa banners.
 */
class AdCampaign extends Model
{
    protected $table = 'ad_campaigns';

    /** Estados. Se nombran para que las consultas no queden con números sueltos. */
    public const BORRADOR   = 0;
    public const ACTIVA     = 1;
    public const PAUSADA    = 2;
    public const FINALIZADA = 3;

    protected $fillable = [
        'advertiser_id',
        'name',
        'description',
        'objective',
        'starts_at',
        'ends_at',
        'budget',
        'state',
    ];

    protected $casts = [
        'advertiser_id' => 'integer',
        'starts_at'     => 'date',
        'ends_at'       => 'date',
        'budget'        => 'decimal:2',
        'state'         => 'integer',
    ];

    public function advertiser(): BelongsTo
    {
        return $this->belongsTo(Advertiser::class, 'advertiser_id');
    }

    public function banners(): HasMany
    {
        return $this->hasMany(Banner::class, 'campaign_id');
    }

    /**
     * Campañas que hoy pueden entregar publicidad.
     *
     * Se comparan fechas y no marcas de tiempo: la vigencia se pacta por día
     * ("del 1 al 31"), y con `now()` completo una campaña que termina el 31
     * dejaría de servir a las 00:00:01 de ese mismo día.
     */
    public function scopeVigente(Builder $q): Builder
    {
        return $q->where('state', self::ACTIVA)
            ->whereDate('starts_at', '<=', now()->toDateString())
            ->whereDate('ends_at', '>=', now()->toDateString());
    }
}
