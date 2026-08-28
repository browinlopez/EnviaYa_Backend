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
    /*
     * Ahora sí lleva `created_at` / `updated_at`.
     *
     * Estaba en `false` porque la tabla no tenía las columnas, y sin ellas no
     * se podía responder desde cuándo existe cada registro — que es la mitad
     * de lo que pregunta cualquier reporte de crecimiento.
     *
     * La base también las rellena por su cuenta (`DEFAULT CURRENT_TIMESTAMP`),
     * porque el proyecto inserta tanto por Eloquent como por el constructor de
     * consultas y sólo uno de los dos caminos pasa por aquí.
     */
    public $timestamps = true;

    protected $fillable = [
        'name',
        'phone',
        'address',
        'description',
        'latitude',
        'longitude',
        'qualification',
        'razonSocial_DCD',
        "municipality_id",
        'NIT',
        'logo',
        'city',
        'type',
        'state',
        /*
         * Cuánto efectivo deja que un domiciliario lleve encima. Sin esto en
         * `fillable`, `fill()` lo descartaba EN SILENCIO: el tendero guardaba
         * el tope, la pantalla decía «actualizado» y la columna seguía nula.
         */
        'max_courier_cash',
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
        return $this->hasMany(
            BusinessReview::class,
            'busines_id',   // 👈 columna en business_reviews
            'busines_id'
        );
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
