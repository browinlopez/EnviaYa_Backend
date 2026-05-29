<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BusinessReview extends Audit
{
    public $timestamps = false;

    protected $fillable = [
        'business_id',
        'buyer_id',
        'qualification',
        'comment',
        'state'
    ];

    public function business()
    {
        return $this->belongsTo(Business::class, 'business_id', 'id');
    }

    public function buyer()
    {
        return $this->belongsTo(Buyer::class, 'buyer_id', 'id');
    }
}
