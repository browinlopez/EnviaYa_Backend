<?php

namespace App\Models\Marketing;

use App\Models\Business;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Anunciante: marca externa o negocio de la plataforma.
 */
class Advertiser extends Model
{
    protected $table = 'advertisers';

    protected $fillable = [
        'name',
        'business_id',
        'contact_name',
        'contact_email',
        'contact_phone',
        'tax_id',
        'notes',
        'state',
    ];

    protected $casts = [
        'business_id' => 'integer',
        'state'       => 'integer',
    ];

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class, 'business_id', 'busines_id');
    }

    public function campaigns(): HasMany
    {
        return $this->hasMany(AdCampaign::class, 'advertiser_id');
    }
}
