<?php

namespace App\Models\Buyer;

use App\Models\Audit\Audit;

class BuyerComplex extends Audit
{
    protected $table = 'buyer_complex';
    protected $primaryKey = 'id';
    public $timestamps = true;

    protected $fillable = ['buyer_id', 'complex_id'];

    public function buyer()
    {
        return $this->belongsTo(Buyer::class, 'buyer_id', 'buyer_id');
    }

    public function complex()
    {
        return $this->belongsTo(ResidentialComplex::class, 'complex_id', 'complex_id');
    }

    public function residentialComplex()
    {
        return $this->belongsTo(ResidentialComplex::class, 'complex_id', 'complex_id');
    }
}
