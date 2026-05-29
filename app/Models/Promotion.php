<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;

class Promotion extends Audit
{
    use HasFactory;

    public $timestamps = false;

    protected $fillable = [
        'business_id',
        'code_promotions',
        'description',
        'percentage_discount',
        'start_date',
        'end_date',
        'state'
    ];

    public function business()
    {
        return $this->belongsTo(Business::class, 'business_id', 'id');
    }

    public function orders()
    {
        return $this->hasMany(OrderPromotion::class, 'promotion_id', 'id');
    }
}
