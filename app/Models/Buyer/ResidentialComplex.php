<?php

namespace App\Models\Buyer;

use App\Models\Audit\Audit;

class ResidentialComplex extends Audit
{
    protected $table = 'residential_complexes';
    protected $primaryKey = 'complex_id';
    public $timestamps = false;

    protected $fillable = ['name', 'address', 'state', 'people_count'];

    public function buyers()
    {
        return $this->hasMany(BuyerComplex::class, 'complex_id', 'complex_id');
    }
}
