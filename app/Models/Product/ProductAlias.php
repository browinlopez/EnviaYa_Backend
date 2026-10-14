<?php

namespace App\Models\Product;

use Illuminate\Database\Eloquent\Model;

/**
 * Cómo llama una tienda a un producto del catálogo.
 *
 * El código de barras ya hace esto cuando existe: es el mismo número en todas
 * partes. Esto es para lo que no lo tiene —en el inventario real de una tienda,
 * el 42% de las filas— y para los nombres que no coinciden: el archivo dice
 * «ACETAMINOFEN» y el catálogo tiene «ACETAMINOFEN TABLETAS 500MG XUNIDAD».
 *
 * Que esos dos sean el mismo producto es una decisión humana. Esta tabla es
 * donde se guarda para que la siguiente carga no la tenga que volver a tomar
 * —ni, peor, tomarla mal y duplicar el producto.
 */
class ProductAlias extends Model
{
    protected $table = 'product_aliases';
    protected $primaryKey = 'alias_id';
    public $timestamps = true;

    protected $fillable = ['products_id', 'alias', 'busines_id', 'origen'];

    /**
     * «Vale para todas las tiendas».
     *
     * Un 0 y no un NULL, y no es un capricho: en SQL dos NULL se consideran
     * distintos, así que un único sobre (alias, busines_id) NO impide dos
     * aliases generales iguales —que es justamente el caso que hay que
     * impedir, porque entonces la consulta elige a ciegas entre dos productos.
     * Con 0 el único funciona. El precio es que esa columna no puede llevar
     * clave foránea, porque 0 no es ningún negocio.
     */
    public const TODAS = 0;

    public function product()
    {
        return $this->belongsTo(Product::class, 'products_id', 'products_id');
    }

    /**
     * El nombre tal como se guarda y se busca: sin tildes, en mayúsculas y con
     * los espacios colapsados.
     *
     * Normalizar en un solo sitio es lo que hace que el índice sirva. Y lo de
     * los espacios no es cosmético: tres de los doce duplicados que aparecieron
     * en producción eran el mismo nombre con un espacio de más.
     */
    public static function normalizar(string $nombre): string
    {
        /*
         * Tabla explícita y no `iconv`. Con TRANSLIT, «É» se convierte en
         * «'E» y «Ñ» en «~N»; el apóstrofo y la virgulilla se iban después
         * como símbolos y quedaban «CAF E TOSTAO» y «PI NA», dos palabras
         * donde había una. Es el mismo fallo que apareció leyendo los nombres
         * del archivo del punto de venta, y en un catálogo colombiano hay
         * tildes y eñes en todas partes.
         */
        $n = strtr($nombre, [
            'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n',
            'Á' => 'A', 'É' => 'E', 'Í' => 'I', 'Ó' => 'O', 'Ú' => 'U', 'Ü' => 'U', 'Ñ' => 'N',
            'à' => 'a', 'è' => 'e', 'ì' => 'i', 'ò' => 'o', 'ù' => 'u',
            'À' => 'A', 'È' => 'E', 'Ì' => 'I', 'Ò' => 'O', 'Ù' => 'U',
        ]);

        $n = mb_strtoupper($n, 'UTF-8');
        $n = preg_replace('/[^A-Z0-9 ]+/u', ' ', $n);

        return trim(preg_replace('/\s+/', ' ', $n));
    }

    /**
     * El producto que una tienda llama así, si alguien ya lo decidió.
     *
     * Primero el alias de ESA tienda y después el general: un local puede
     * tener una manía propia con un nombre que en el resto significa otra cosa.
     */
    public static function resolver(string $nombre, ?int $businessId = null): ?int
    {
        $clave = self::normalizar($nombre);

        if ($clave === '') {
            return null;
        }

        return self::where('alias', $clave)
            ->whereIn('busines_id', array_filter([self::TODAS, $businessId], fn ($v) => $v !== null))
            // El de la tienda primero; el general es el que vale para todas.
            ->orderByDesc('busines_id')
            ->value('products_id');
    }
}
