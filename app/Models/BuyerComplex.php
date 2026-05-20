<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BuyerComplex extends Model
{
    public $timestamps = true;

    protected $fillable = ['buyer_id', 'complex_id'];

    public function buyer()
    {
        return $this->belongsTo(Buyer::class, 'buyer_id', 'id');
    }

    public function complex()
    {
        return $this->belongsTo(ResidentialComplex::class, 'complex_id', 'id');
    }

    public function residentialComplex()
    {
        return $this->belongsTo(ResidentialComplex::class, 'complex_id', 'id');
    }
}
