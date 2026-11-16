<?php

declare(strict_types=1);

namespace App\Models\Marketing;

use App\Models\Business;
use App\Support\FiltroDeFechas;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Posicionamiento pagado de un negocio.
 */
class FeaturedBusiness extends Model
{
    protected $table = 'featured_businesses';

    protected $fillable = [
        'business_id',
        'placement',
        'priority',
        'starts_at',
        'ends_at',
        'paid_amount',
        'state',
    ];

    protected $casts = [
        'business_id' => 'integer',
        'priority' => 'integer',
        'starts_at' => 'date',
        'ends_at' => 'date',
        'paid_amount' => 'decimal:2',
        'impressions_count' => 'integer',
        'clicks_count' => 'integer',
        'state' => 'integer',
    ];

    /** @return BelongsTo<Business, $this> */
    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class, 'business_id', 'busines_id');
    }

    /**
     * Destaques en curso hoy.
     *
     * Las columnas van calificadas con su tabla porque este scope se usa junto
     * a un join con `business`, que también tiene `state`: sin calificar,
     * MySQL responde "Column 'state' in where clause is ambiguous" y el
     * endpoint público se cae entero. SQLite no lo detecta, así que el fallo
     * solo aparecía contra la base real.
     */
    public function scopeVigente(Builder $q): Builder
    {
        $hoy = now()->toDateString();

        $q = $q->where($q->qualifyColumn('state'), 1);

        // Como rangos: ver `FiltroDeFechas`.
        $q = FiltroDeFechas::hasta($q, $q->qualifyColumn('starts_at'), $hoy);

        return FiltroDeFechas::desde($q, $q->qualifyColumn('ends_at'), $hoy);
    }
}
