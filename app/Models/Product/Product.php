<?php

namespace App\Models\Product;

use App\Models\Audit\Audit;
use App\Models\Business;
use App\Models\Product\ProductAlias;
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

    /**
     * CADA PRODUCTO NACE CON SU NOMBRE NORMALIZADO COMO ALIAS.
     *
     * Va en un evento del modelo y no dentro de `CatalogoDeProductos::proponer`
     * porque los productos entran por varios sitios —la propuesta del tendero,
     * la carga de un archivo, el panel de administración— y el que se olvide
     * de crear el alias no falla: deja un producto que la siguiente carga no
     * va a reconocer y va a duplicar.
     *
     * Lo que esto compra es que «AJINOMEN CARNE  80 GR» encuentre a «AJINOMEN
     * CARNE 80 GR». Tres de los doce duplicados que aparecieron en producción
     * eran exactamente eso: un espacio de más.
     *
     * SI EL NOMBRE ES AMBIGUO, NO HAY ALIAS, Y ES A PROPÓSITO. El catálogo
     * tiene «PANELA» dos veces. Dejar el alias apuntando al primero haría que
     * una carga eligiera uno de los dos en silencio, y el precio acabaría en
     * el producto equivocado la mitad de las veces.
     *
     * Sin alias, la carga cae en el camino de siempre: encuentra dos productos
     * que se llaman igual y RECHAZA la fila pidiendo el código de barras, que
     * es la pregunta correcta y la que el código de barras existe para
     * responder. Por eso, al detectar la colisión, se borra también el alias
     * del que ya lo tenía.
     */
    protected static function booted(): void
    {
        static::created(function (self $producto) {
            $alias = ProductAlias::normalizar((string) $producto->name);

            if ($alias === '') {
                return;
            }

            $ocupado = ProductAlias::where('alias', $alias)
                ->where('busines_id', ProductAlias::TODAS)
                ->first();

            if ($ocupado) {
                // Dos productos distintos con el mismo nombre: ninguno de los
                // dos puede quedarse el alias.
                if ((int) $ocupado->products_id !== (int) $producto->products_id
                    && $ocupado->origen === 'automatico') {
                    $ocupado->delete();
                }

                return;
            }

            ProductAlias::insertOrIgnore([
                'products_id' => $producto->products_id,
                'alias'       => $alias,
                'busines_id'  => ProductAlias::TODAS,
                'origen'      => 'automatico',
                'created_at'  => now(),
                'updated_at'  => now(),
            ]);
        });
    }

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
