<?php

namespace App\Models;

use App\Models\Audit\Audit;
use App\Models\Order\OrderPromotion;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Promotion extends Audit
{
    use HasFactory;

    protected $table = 'promotions';
    protected $primaryKey = 'promotion_id';
    public $timestamps = false;

    protected $fillable = [
        'busines_id',
        'code_promotions',
        'description',
        'percentage_discount',
        'start_date',
        'end_date',
        'state'
    ];

    public function business()
    {
        return $this->belongsTo(Business::class, 'busines_id', 'busines_id');
    }

    public function orders()
    {
        return $this->hasMany(OrderPromotion::class, 'promotion_id', 'promotion_id');
    }
}
