<?php

namespace App\Models\Marketing;

use App\Models\Business;
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
        'business_id'       => 'integer',
        'priority'          => 'integer',
        'starts_at'         => 'date',
        'ends_at'           => 'date',
        'paid_amount'       => 'decimal:2',
        'impressions_count' => 'integer',
        'clicks_count'      => 'integer',
        'state'             => 'integer',
    ];

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class, 'business_id', 'busines_id');
    }

    public function scopeVigente(Builder $q): Builder
    {
        $hoy = now()->toDateString();

        return $q->where('state', 1)
            ->whereDate('starts_at', '<=', $hoy)
            ->whereDate('ends_at', '>=', $hoy);
    }
}
