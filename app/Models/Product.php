<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

class Product extends Audit
{
    protected $table = 'products';
    public $timestamps = false;

    protected $fillable = ['name', 'description', 'category_id', 'image', 'state', 'business_id'];

    public function category()
    {
        return $this->belongsTo(Category::class, 'category_id', 'id');
    }

    public function productBusinesses()
    {
        return $this->hasMany(
            ProductBusiness::class,
            'products_id',
            'id'
        );
    }

    public function sales()
    {
        return $this->hasMany(OrderSale::class, 'product_id', 'id');
    }

    public function salesDetails()
    {
        return $this->hasMany(OrderSaleDetail::class, 'product_id', 'id');
    }

    // ✔ ACCESO A BUSINESS A TRAVÉS DE LA PIVOTE
    public function businesses()
    {
        return $this->belongsToMany(
            Business::class,
            'product_businesses',
            'products_id',
            'business_id'
        )->withPivot([
                    'id',
                    'price',
                    'quantity',
                    'qualification'
                ]);
    }

    public function grocery()
    {
        return $this->hasOne(GroceryProduct::class, 'products_id', 'id');
    }

    public function pharmacy()
    {
        return $this->hasOne(PharmacyProduct::class, 'products_id', 'id');
    }

    public function restaurant()
    {
        return $this->hasOne(RestaurantProducts::class, 'products_id', 'id');
    }

    public function carPart()
    {
        return $this->hasOne(CarPartsProducts::class, 'products_id', 'id');
    }

    public function getImageUrlAttribute(): string
    {
        if ($this->image && Storage::disk('public')->exists($this->image)) {
            return Storage::url($this->image);
        }

        return asset('img/default-product.png');
    }
}
