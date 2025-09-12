<?php

namespace App\Models;

use App\Models\Audit\Audit;
use App\Models\Business\CategoryBusiness;
use App\Models\Maps\Municipality;
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
        "municipality_id",
        'NIT',
        'logo',
        'city',
        'type',
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

    public function municipality()
    {
        return $this->belongsTo(Municipality::class, 'municipality_id', 'id'); // ajusta según tu pk de municipalities
    }

    //relaciones
    public function products()
    {
        return $this->belongsToMany(
            Product::class,
            'products_business',
            'busines_id',
            'products_id'
        )->withPivot(['price', 'amount', 'qualification']);
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

    public function domiciliaries()
    {
        return $this->belongsToMany(Domiciliary::class, 'business_domiciliary', 'busines_id', 'domiciliary_id')
            ->withPivot('state');
    }

    public function usersWhoFavorite()
    {
        return $this->belongsToMany(User::class, 'business_user_favorites', 'busines_id', 'user_id');
    }

    public function category()
    {
        return $this->belongsTo(CategoryBusiness::class, 'type', 'id');
    }
}
