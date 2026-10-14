<?php

namespace App\Models\Product;

use Illuminate\Database\Eloquent\Model;

/**
 * Un producto como lo piensa quien compra: «Salsa de tomate San Jorge».
 *
 * Lo que se VENDE son sus variantes, que viven en `products` y conservan cada
 * una su código de barras, su precio por tienda y su historial de pedidos. El
 * grupo no participa en ninguna venta: es el título de la tarjeta y el sitio
 * del que cuelgan los chips de presentación.
 *
 * Por eso no extiende `Audit`. La auditoría registra quién cambió qué en los
 * datos de un negocio, y un grupo no es de ningún negocio: es del catálogo
 * compartido, y lo que de verdad hay que poder auditar —el precio, las
 * existencias, el nombre del producto— ya se audita donde vive.
 */
class ProductGroup extends Model
{
    protected $table = 'product_groups';
    protected $primaryKey = 'group_id';
    public $timestamps = true;

    protected $fillable = ['name', 'brand', 'category_id', 'image', 'description', 'origen'];

    public function products()
    {
        return $this->hasMany(Product::class, 'group_id', 'group_id');
    }

    public function category()
    {
        return $this->belongsTo(Category::class, 'category_id', 'category_id');
    }

    /**
     * La foto del grupo, o la de la primera variante que tenga una.
     *
     * Las variantes de un mismo producto se parecen entre sí, así que la foto
     * de cualquiera sirve para la tarjeta. Esto evita tener que subir una foto
     * más solo para el grupo, y evita el caso feo de un grupo sin imagen cuyas
     * variantes sí la tienen.
     */
    public function imagenParaMostrar(): ?string
    {
        if ($this->image) {
            return $this->image;
        }

        return $this->products->firstWhere(fn ($p) => !empty($p->image))?->image;
    }
}
