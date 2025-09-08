<?php

namespace App\Models\Buyer;

use Illuminate\Database\Eloquent\Model;

class ResidentialComplex extends Model
{
    protected $table = 'residential_complexes';
    protected $primaryKey = 'complex_id';
    public $timestamps = true;

    protected $fillable = ['name', 'state', 'people_count'];

    public function buyers()
    {
        return $this->hasMany(BuyerComplex::class, 'complex_id', 'complex_id');
    }
}
