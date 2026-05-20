<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Business extends Audit
{
    protected $table = 'business';
    public $timestamps = false;

    protected $fillable = [
        'name',
        'phone',
        'address',
        'description',
        'latitude',
        'longitude',
        'qualification',
        'legal_name',
        'type_organization_id',
        "municipality_id",
        'identification_number',
        'verification_digit',
        'logo',
        'category_business_id',
        'state'
    ];

    public function owners()
    {
        return $this->belongsToMany(
            Owner::class,
            'owner_businesses',
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
            'product_businesses',
            'busines_id',
            'products_id'
        )->withPivot(['id', 'price', 'quantity', 'qualification']);
    }

    public function orders()
    {
        return $this->hasMany(OrderSale::class, 'busines_id', 'id');
    }

    public function reviews()
    {
        return $this->hasMany(
            BusinessReview::class,
            'busines_id',   // 👈 columna en business_reviews
            'id'
        );
    }


    public function productBusinesses()
    {
        return $this->hasMany(ProductBusiness::class, 'busines_id', 'id');
    }

    public function domiciliaries()
    {
        return $this->belongsToMany(Domiciliary::class, 'business_domiciliaries', 'busines_id', 'domiciliary_id')
            ->withPivot('state');
    }

    public function usersWhoFavorite()
    {
        return $this->belongsToMany(User::class, 'business_user_favorites', 'busines_id', 'user_id');
    }

    public function category()
    {
        return $this->belongsTo(CategoryBusiness::class, 'category_business_id', 'id');
    }

    public function typeOrganization()
    {
        return $this->belongsTo(TypeOrganization::class, 'type_organization_id', 'id');
    }
}
