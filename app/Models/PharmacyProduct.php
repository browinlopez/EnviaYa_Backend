<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PharmacyProduct extends Model
{
    public $timestamps = false;

    protected $fillable = ['products_id', 'active_ingredient', 'dosage', 'presentation', 'expiration_date'];

    public function product()
    {
        return $this->belongsTo(Product::class, 'products_id', 'id');
    }
}
