<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CarPartsProducts extends Model
{

    protected $fillable = [
        'products_id',
        'brand',
        'model',
        'year',
        'oem_code',
        'compatibility',
    ];

    public function product()
    {
        return $this->belongsTo(Product::class, 'products_id', 'id');
    }
}
