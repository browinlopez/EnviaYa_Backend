<?php

namespace App\Models\Product;

use App\Models\Audit\Audit;
use App\Models\Business;
use App\Models\Order\OrdersSales;
use Illuminate\Database\Eloquent\Model;

class Product extends Audit
{
    protected $table = 'products';
    protected $primaryKey = 'products_id';
    public $timestamps = false;

    protected $fillable = ['name', 'description', 'category_id', 'state'];

    public function category()
    {
        return $this->belongsTo(Category::class, 'category_id', 'category_id');
    }

    public function productBusinesses()
    {
        return $this->hasMany(ProductBusiness::class, 'products_id', 'products_id');
    }

    public function sales()
    {
        return $this->hasMany(OrdersSales::class, 'product_id', 'products_id');
    }

    public function businesses()
    {
        return $this->belongsToMany(
            Business::class,
            'products_business',
            'products_id',
            'busines_id'
        );
    }

    public function grocery()
    {
        return $this->hasOne(GroceryProduct::class, 'products_id', 'products_id');
    }

    public function pharmacy()
    {
        return $this->hasOne(PharmacyProduct::class, 'products_id', 'products_id');
    }
}
