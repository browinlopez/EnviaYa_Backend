<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Audit\Audit;
use App\Models\Business\CategoryBusiness;
use App\Models\Maps\Municipality;
use App\Models\Order\OrdersSales;
use App\Models\Owner\Owner;
use App\Models\Product\Product;
use App\Models\Product\ProductBusiness;
use App\Models\Reviews\BusinessReview;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * EL NEGOCIO.
 *
 * Las dos columnas de la pausa van anotadas porque Larastan no las ve: sus
 * moldes estan en el metodo `casts()` --la forma de Laravel 11-- y esta
 * version de Larastan solo lee la PROPIEDAD `$casts`. Sin la anotacion, cree
 * que son cadenas y da «Cannot call method toIso8601String() on string» en un
 * sitio donde en ejecucion hay un Carbon. Comprobado: hay una fila con
 * `accepting_changed_at` y el modelo devuelve `Illuminate\Support\Carbon`.
 *
 * `recibiendo_ahora` es un accessor, no una columna: ver `recibiendoAhora()`.
 *
 * PENDIENTE, y no es cosa de este modelo: `scripts/anotar_modelos.php` escribe
 * el bloque `@property` de los setenta modelos leyendo las migraciones, y
 * NUNCA SE HA CORRIDO --`ya tenian @property: 0`--. El guion llevaba roto por
 * el orden de los `use` y nadie lo llamaba. Correrlo quitaria buena parte de
 * las supresiones que quedan en el baseline, y es su propia tanda.
 *
 * @property Carbon|null $accepting_until
 * @property Carbon|null $accepting_changed_at
 * @property bool $recibiendo_ahora
 */
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
        'municipality_id',
        'NIT',
        'logo',
        'type',
        'state',
        /*
         * Cuánto efectivo deja que un domiciliario lleve encima. Sin esto en
         * `fillable`, `fill()` lo descartaba EN SILENCIO: el tendero guardaba
         * el tope, la pantalla decía «actualizado» y la columna seguía nula.
         */
        'max_courier_cash',
        /*
         * Tope de crédito de la tienda: cuánto puede fiar entre todos sus
         * clientes y cuánta deuda puede arrastrar con la plataforma. Lo pone
         * el equipo, nunca el tendero. Ver `CreditoDeTienda`.
         */
        'credit_debt_cap',
        /*
         * «Recibiendo pedidos»: la pausa del mostrador. Es del tendero y dura
         * lo que dure el motivo (se acabó el gas, se quedó solo). NO es
         * `state`, que es el alta y la baja de la plataforma y el tendero no
         * puede tocar. Ver la migración `recibiendo_pedidos_en_business`.
         */
        'accepting_orders',
        'accepting_changed_at',
        // Hasta cuándo dura la pausa. Nula = sin hora, la enciende él.
        'accepting_until',
    ];

    protected function casts(): array
    {
        return [
            // Sin esto llega como "1"/"0" y el panel pinta un interruptor
            // encendido con la cadena "0", que es verdadera en JavaScript.
            'accepting_orders' => 'boolean',
            'accepting_changed_at' => 'datetime',
            'accepting_until' => 'datetime',

            /*
             * `qualification` es `decimal(3,2)`: MySQL la devuelve como cadena
             * y SQLite como número, así que sin molde el tipo del campo en la
             * respuesta dependía del motor. Queda en cadena en los dos, que es
             * lo que ya recibe la app en producción.
             */
            'qualification' => 'decimal:2',
        ];
    }

    /**
     * ¿ESTÁ RECIBIENDO PEDIDOS AHORA?
     *
     * Es la única respuesta buena a esa pregunta, y hay tres sitios que la
     * hacen: el escaparate, la ficha y la creación del pedido. La columna sola
     * no sirve, porque una pausa «hasta las 3» sigue valiendo 0 a las 4.
     *
     * Se calcula al leer y no con una tarea programada que vaya encendiendo
     * tiendas: así es correcta siempre, y a la misma hora para todos. Ver la
     * migración `pausa_con_hora_de_vuelta`.
     */
    public function getRecibiendoAhoraAttribute(): bool
    {
        if ($this->accepting_orders) {
            return true;
        }

        return $this->accepting_until !== null && $this->accepting_until->isPast();
    }

    /**
     * Lo mismo, en SQL, para los listados: o está encendida, o su pausa ya
     * venció. Tiene que decir exactamente lo que dice el accessor de arriba —
     * dos definiciones de «abierta» acaban enseñando en la lista una tienda
     * que luego rechaza el pedido.
     */
    public function scopeRecibiendo($query)
    {
        return $query->where(function ($q) {
            $q->where('accepting_orders', 1)
                ->orWhere(function ($q2) {
                    $q2->whereNotNull('accepting_until')
                        ->where('accepting_until', '<=', now());
                });
        });
    }

    /** @return BelongsToMany<Owner, $this> */
    public function owners(): BelongsToMany
    {
        return $this->belongsToMany(
            Owner::class,
            'owner_busines',
            'busines_id',
            'owner_id'
        )->withPivot('state');
    }

    /** @return BelongsTo<Municipality, $this> */
    public function municipality(): BelongsTo
    {
        return $this->belongsTo(Municipality::class, 'municipality_id', 'id');
    }

    // relaciones
    /**
     * El surtido de la tienda, con las columnas del par tienda-producto.
     *
     * LAS TRES ÚLTIMAS SON EL ARREGLO DE LA VENTA AL PESO, y conviene saber
     * qué pasaba sin ellas.
     *
     * `sale_unit`, `min_qty` y `step_qty` están en `products_business` desde
     * la migración del peso, y dos sitios las leen —la ficha del negocio y el
     * listado de destacados— con la forma `$product->pivot->sale_unit ??
     * 'unidad'`. Pero no estaban en este `withPivot`, así que la relación no
     * las traía: el pivote llegaba sin esas claves, el `??` se disparaba
     * siempre y la respuesta decía `unidad` aunque la tienda hubiera
     * configurado kilos.
     *
     * NO DABA NINGÚN ERROR. Un pivote al que le falta una columna devuelve
     * null, no revienta, así que el camino entero —migración, columna,
     * lectura, serialización— estaba bien escrito y no funcionaba.
     *
     * Medido antes de tocarlo, de dos maneras: una prueba que siembra un
     * producto en `kg` y recibía `unidad`, y a mano contra la base de
     * desarrollo —se puso una fila a `kg`, se cargó por la relación y salió
     * `NULL`—.
     *
     * LO QUE ARREGLA, EN PANTALLA:
     *
     *  · la app recibe la unidad de verdad, que es con lo que decide si
     *    ofrece el +/- de unidades o los botones de plata y de porción
     *    —«3.000 de queso», «500 g»—;
     *  · y la ficha del negocio deja de etiquetar como «en promoción» lo que
     *    se vende al peso. Ese filtro es
     *    `in_array($pivot->sale_unit, ['kg','libra','gramo'])` y, viendo
     *    siempre `unidad`, nunca excluía nada: la etiqueta prometía una
     *    rebaja que el motor de descuentos no aplica a esas líneas, así que
     *    el cliente llegaba al total esperando otro número.
     *
     * NO CAMBIA NINGUNA RESPUESTA DE HOY, y eso también está medido: las 639
     * filas de `products_business` están en `unidad`, así que no hay ni un
     * producto al peso configurado todavía. Comprobado byte a byte en los
     * cuatro endpoints del catálogo. Esto no enciende un comportamiento
     * nuevo: hace que el que ya existía deje de estar roto el día que alguien
     * lo use.
     *
     * `stock_weight` NO se añade. Es la existencia en kilos y la escribe y la
     * lee `ExistenciasDelPedido` por `DB::table`, que no pasa por aquí; traer
     * una columna que nadie lee desde la relación es peso en cada consulta
     * del catálogo, que es la lectura de más tráfico de la plataforma.
     *
     * @return BelongsToMany<Product, $this>
     */
    public function products(): BelongsToMany
    {
        return $this->belongsToMany(
            Product::class,
            'products_business',
            'busines_id',
            'products_id'
        )->withPivot([
            'price',
            'amount',
            'qualification',
            'sale_unit',
            'min_qty',
            'step_qty',
            'por_valor',
            'stock_weight',
            // La categoría de ESTE negocio para el producto, si la tiene.
            'category_id',
            // Lo que le cuesta. Sólo lo publica el catálogo del dueño.
            'costo',
        ]);
    }

    /** @return HasMany<OrdersSales, $this> */
    public function orders(): HasMany
    {
        return $this->hasMany(OrdersSales::class, 'busines_id', 'busines_id');
    }

    /** @return HasMany<BusinessReview, $this> */
    public function reviews(): HasMany
    {
        return $this->hasMany(
            BusinessReview::class,
            'busines_id',   // 👈 columna en business_reviews
            'busines_id'
        );
    }

    /** @return HasMany<ProductBusiness, $this> */
    public function productBusinesses(): HasMany
    {
        return $this->hasMany(ProductBusiness::class, 'busines_id', 'busines_id');
    }

    /**
     * LA FLOTA DE LA TIENDA: SOLO LOS VÍNCULOS VIVOS.
     *
     * `business_domiciliary` tiene su `state` desde siempre y esta relación
     * lo traía --`withPivot`-- pero no filtraba por él. No se notaba porque
     * nada lo ponía nunca en 0: no había forma de quitar a nadie de una
     * tienda.
     *
     * Al añadir `DELETE /negocio/domiciliarios/{id}` salió a la primera: se
     * desvinculaba a alguien y la torre, el elegidor de «despachar» y el
     * efectivo lo seguían enseñando, porque los tres leen de acá.
     *
     * Lo cazó la prueba «y deja de salir en la torre», que es justo el tipo
     * de cosa que no se ve mirando el endpoint nuevo: el fallo estaba en la
     * pieza vieja que él destapa.
     *
     * @return BelongsToMany<Domiciliary, $this>
     */
    public function domiciliaries(): BelongsToMany
    {
        return $this->belongsToMany(Domiciliary::class, 'business_domiciliary', 'busines_id', 'domiciliary_id')
            ->withPivot('state')
            ->wherePivot('state', 1);
    }

    /** @return BelongsToMany<User, $this> */
    public function usersWhoFavorite(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'business_user_favorites', 'busines_id', 'user_id');
    }

    /** @return BelongsTo<CategoryBusiness, $this> */
    public function category(): BelongsTo
    {
        return $this->belongsTo(CategoryBusiness::class, 'type', 'id');
    }
}
