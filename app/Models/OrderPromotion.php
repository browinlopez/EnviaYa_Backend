<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class OrderPromotion extends Audit
{
    use HasFactory;

    public $timestamps = false;

    protected $fillable = ['order_sales_id', 'promotion_id', 'state'];

    public function promotion()
    {
        return $this->belongsTo(Promotion::class, 'promotion_id', 'id');
    }
}
