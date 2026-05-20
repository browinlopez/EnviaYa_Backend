<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class GroceryProduct extends Model
{
    public $timestamps = false;

    protected $fillable = ['products_id', 'brand', 'size', 'expiration_date'];

    public function product()
    {
        return $this->belongsTo(Product::class, 'products_id', 'id');
    }
}
