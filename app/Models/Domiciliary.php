<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Audit\Audit;
use App\Models\Order\OrderGeolocation;
use App\Models\Order\OrdersSales;
use App\Models\Reviews\DomiciliaryReview;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Domiciliary extends Audit
{
    protected $table = 'domiciliary';

    protected $primaryKey = 'domiciliary_id';

    public $timestamps = false;

    protected $fillable = ['user_id', 'available', 'document',  'qualification', 'state', 'registrado_por_negocio'];

    /*
     * `qualification` es `decimal(3,2)` y PDO de MySQL devuelve DECIMAL como
     * CADENA, mientras SQLite lo devuelve numérico. Sin molde, el tipo de este
     * campo en la respuesta dependía del motor: la suite veía 0 y la app
     * instalada recibía "0.00". El molde lo fija en cadena en los dos, que es
     * lo que ya manda producción, así que la respuesta no cambia.
     */
    protected $casts = [
        'qualification' => 'decimal:2',
    ];

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id', 'user_id');
    }

    /** @return HasMany<DomiciliaryReview, $this> */
    public function reviews(): HasMany
    {
        return $this->hasMany(DomiciliaryReview::class, 'domiciliary_id', 'domiciliary_id');
    }

    /**
     * Las posiciones que ha ido dejando este domiciliario.
     *
     * ESTABA ROTA, y no por poco: faltaba el `use`, asi que
     * `OrderGeolocation::class` se resolvia contra ESTE espacio de nombres y
     * daba `App\Models\OrderGeolocation`, que no existe --la clase vive en
     * `App\Models\Order\`--. Llamarla reventaba con «Class not found».
     *
     * No lo notaba nadie porque no la llama nadie: la torre de control lee
     * `order_geolocation` por `DB::table`. `::class` no comprueba que la
     * clase exista, asi que ni PHP ni Pint dicen nada; lo saco el tipo de
     * retorno, que es justo para lo que se puso.
     *
     * @return HasMany<OrderGeolocation, $this>
     */
    public function geolocation(): HasMany
    {
        return $this->hasMany(OrderGeolocation::class, 'domiciliary_id', 'domiciliary_id');
    }

    /**
     * Pedidos asignados a este domiciliario. Se usa sobre todo para contar
     * los que tiene en curso (estado 3) y así aplicar el tope de entregas
     * simultáneas antes de asignarle uno nuevo.
     *
     * @return HasMany<OrdersSales, $this>
     */
    public function orders(): HasMany
    {
        return $this->hasMany(OrdersSales::class, 'domiciliary_id', 'domiciliary_id');
    }

    /** @return BelongsToMany<Business, $this> */
    public function businesses(): BelongsToMany
    {
        return $this->belongsToMany(Business::class, 'business_domiciliary', 'domiciliary_id', 'busines_id')
            ->withPivot('state');
    }
}
