<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ResidentialComplex extends Model
{
    public $timestamps = false;

    protected $fillable = ['name', 'address', 'state', 'people_count'];

    public function buyers()
    {
        return $this->hasMany(BuyerComplex::class, 'complex_id', 'id');
    }
}
