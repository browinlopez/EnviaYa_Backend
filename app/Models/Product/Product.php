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
        'name', 'barcode', 'description', 'brand', 'category_id', 'image',
        'state', 'origen',
        /* El grupo de variantes y la etiqueta de ESTA presentacion. Ver
           `AgrupadorDeVariantes` y la migracion de `product_groups`. */
        'group_id', 'variante',
    ];

    public function category()
    {
        return $this->belongsTo(Category::class, 'category_id', 'category_id');
    }

    /**
     * El producto como lo piensa quien compra.
     *
     * Casi siempre es null, y esta bien: solo lo tienen los productos que se
     * venden en mas de una presentacion. Lo que se vende, se pide y se cobra
     * sigue siendo ESTA fila; el grupo solo decide como se pinta la lista.
     */
    public function group()
    {
        return $this->belongsTo(ProductGroup::class, 'group_id', 'group_id');
    }

    /** Las demas presentaciones del mismo producto. */
    public function hermanas()
    {
        return $this->hasMany(self::class, 'group_id', 'group_id')
            ->whereKeyNot($this->getKey());
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
