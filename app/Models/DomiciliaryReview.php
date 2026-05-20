<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DomiciliaryReview extends Audit
{
    public $timestamps = false;

    protected $fillable = [
        'domiciliary_id',
        'buyer_id',
        'qualification',
        'comment',
        'state'
    ];

    public function domiciliary()
    {
        return $this->belongsTo(Domiciliary::class, 'domiciliary_id', 'id');
    }

    public function buyer()
    {
        return $this->belongsTo(Buyer::class, 'buyer_id', 'id');
    }
}
