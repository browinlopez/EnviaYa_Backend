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
            'accepting_orders'     => 'boolean',
            'accepting_changed_at' => 'datetime',
            'accepting_until'      => 'datetime',
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
