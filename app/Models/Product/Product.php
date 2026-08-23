<?php

namespace App\Models\Product;

use App\Models\Audit\Audit;
use App\Models\Business;
use App\Models\Order\OrdersSales;
use App\Models\Order\OrdersSalesDetail;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

class Product extends Audit
{
    protected $table = 'products';
    protected $primaryKey = 'products_id';
    public $timestamps = false;

    protected $fillable = ['name', 'barcode', 'description', 'brand', 'category_id', 'image', 'state', 'origen'];

    public function category()
    {
        return $this->belongsTo(Category::class, 'category_id', 'category_id');
    }

    public function productBusinesses()
    {
        return $this->hasMany(
            ProductBusiness::class,
            'products_id',
            'products_id'
        );
    }

    public function sales()
    {
        return $this->hasMany(OrdersSales::class, 'product_id', 'products_id');
    }

    public function salesDetails()
    {
        return $this->hasMany(OrdersSalesDetail::class, 'product_id', 'products_id');
    }

    // ✔ ACCESO A BUSINESS A TRAVÉS DE LA PIVOTE
    public function businesses()
    {
        return $this->belongsToMany(
            Business::class,
            'products_business',
            'products_id',
            'busines_id'
        )->withPivot([
            'busines_products_id',
            'price',
            'amount',
            'qualification'
        ]);
    }

    public function grocery()
    {
        return $this->hasOne(GroceryProduct::class, 'products_id', 'products_id');
    }

    public function pharmacy()
    {
        return $this->hasOne(PharmacyProduct::class, 'products_id', 'products_id');
    }

    public function restaurant()
    {
        return $this->hasOne(RestaurantProducts::class, 'products_id', 'products_id');
    }

    public function carPart()
    {
        return $this->hasOne(CarPartsProducts::class, 'products_id', 'products_id');
    }

    public function getImageUrlAttribute(): string
    {
        if ($this->image && Storage::disk('public')->exists($this->image)) {
            return Storage::url($this->image);
        }

        return asset('img/default-product.png');
    }
}
