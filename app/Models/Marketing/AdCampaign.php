<?php

declare(strict_types=1);

namespace App\Models\Marketing;

use App\Support\FiltroDeFechas;
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
    public const BORRADOR = 0;

    public const ACTIVA = 1;

    public const PAUSADA = 2;

    public const FINALIZADA = 3;

    /**
     * El vocabulario completo, para quien tenga que validarlo.
     *
     * Existe porque `CampanaRequest` escribia `in:0,1,2,3` a mano: dos
     * fuentes de verdad del mismo vocabulario, y por eso las tres
     * constantes de arriba que no usa el scope estaban muertas.
     */
    public const ESTADOS = [self::BORRADOR, self::ACTIVA, self::PAUSADA, self::FINALIZADA];

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
        'starts_at' => 'date',
        'ends_at' => 'date',
        'budget' => 'decimal:2',
        'state' => 'integer',
    ];

    /** @return BelongsTo<Advertiser, $this> */
    public function advertiser(): BelongsTo
    {
        return $this->belongsTo(Advertiser::class, 'advertiser_id');
    }

    /** @return HasMany<Banner, $this> */
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
        $q = $q->where($q->qualifyColumn('state'), self::ACTIVA);

        // Como rangos y no `whereDate`: ver `FiltroDeFechas`. Aqui la tabla es
        // pequenia y la diferencia no se nota hoy; se convierte para que el
        // patron no tenga excepciones que haya que volver a decidir.
        $q = FiltroDeFechas::hasta($q, $q->qualifyColumn('starts_at'), now());

        return FiltroDeFechas::desde($q, $q->qualifyColumn('ends_at'), now());
    }
}
