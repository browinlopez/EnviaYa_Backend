<?php

declare(strict_types=1);

namespace App\Models\Buyer;

use App\Models\Audit\Audit;
use App\Models\Reviews\BusinessReview;
use App\Models\Reviews\DomiciliaryReview;
use App\Models\User;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Buyer extends Audit
{
    protected $table = 'buyer';

    protected $primaryKey = 'buyer_id';

    public $timestamps = false;

    protected $fillable = ['user_id', 'qualification', 'state', 'belongs_to_complex'];

    /*
     * `qualification` es `decimal(3,2)` y PDO de MySQL devuelve DECIMAL como
     * CADENA, mientras SQLite lo devuelve numérico. Sin molde, el tipo de este
     * campo en la respuesta dependía del motor: la suite veía 0 y la app
     * instalada recibía "0.00". El molde lo fija en cadena en los dos, que es
     * lo que ya manda producción, así que la respuesta no cambia.
     */
    protected $casts = [
        'qualification' => 'decimal:2',
    ];

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id', 'user_id');
    }

    /** @return HasMany<BusinessReview, $this> */
    public function businessReviews(): HasMany
    {
        return $this->hasMany(BusinessReview::class, 'buyer_id', 'buyer_id');
    }

    /** @return HasMany<DomiciliaryReview, $this> */
    public function domiciliaryReviews(): HasMany
    {
        return $this->hasMany(DomiciliaryReview::class, 'buyer_id', 'buyer_id');
    }

    /** @return HasMany<BuyerComplex, $this> */
    public function complexes(): HasMany
    {
        return $this->hasMany(BuyerComplex::class, 'buyer_id', 'buyer_id');
    }

    /** @return BelongsToMany<ResidentialComplex, $this> */
    public function residentialComplexes(): BelongsToMany
    {
        return $this->belongsToMany(
            ResidentialComplex::class,
            'buyer_complex',     // tabla pivote
            'buyer_id',          // FK en pivote
            'complex_id'         // FK en pivote
        );
    }
}
