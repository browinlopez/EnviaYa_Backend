<?php

namespace App\Models;

use App\Models\Audit\Audit;
use App\Models\Order\OrdersSales;
use App\Models\Owner\Owner;
use App\Models\Product\Product;
use App\Models\Product\ProductBusiness;
use App\Models\Reviews\BusinessReview;
use Illuminate\Database\Eloquent\Model;

class Business extends Audit
{
    protected $table = 'business';
    protected $primaryKey = 'busines_id';
    public $timestamps = false;

    protected $fillable = [
        'name',
        'phone',
        'address',
        'qualification',
        'razonSocial_DCD',
        'NIT',
        'logo',
        'city',
        'state'
    ];

    public function owners()
    {
        return $this->belongsToMany(
            Owner::class,
            'owner_busines',
            'busines_id',
            'owner_id'
        )->withPivot('state');
    }

    //relaciones
    public function products()
    {
        return $this->belongsToMany(Product::class, 'products_business', 'busines_id', 'products_id')
            ->withPivot('price');
    }

    public function orders()
    {
        return $this->hasMany(OrdersSales::class, 'busines_id', 'busines_id');
    }

    public function reviews()
    {
        return $this->hasMany(BusinessReview::class, 'busines_id', 'busines_id');
    }

    public function productBusinesses()
    {
        return $this->hasMany(ProductBusiness::class, 'busines_id', 'busines_id');
    }
}
