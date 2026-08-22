<?php

namespace App\Models\Buyer;

use App\Models\Audit\Audit;

class ResidentialComplex extends Audit
{
    protected $table = 'residential_complexes';
    protected $primaryKey = 'complex_id';
    public $timestamps = false;

    protected $fillable = [
        'name',
        'address',
        'state',
        'people_count',
        /*
         * Faltaban las tres primeras y el CRUD del panel no lo notaba porque
         * usa Query Builder. Cualquier `create()` las habría descartado en
         * silencio, que es de los fallos más difíciles de ver.
         */
        'latitude',
        'longitude',
        'municipality_id',
        'towers_count',
        'apartments_per_tower',
    ];

    public function buyers()
    {
        return $this->hasMany(BuyerComplex::class, 'complex_id', 'complex_id');
    }
}
