<?php

declare(strict_types=1);

namespace App\Models\User;

use App\Models\Audit\Audit;
use App\Models\Buyer\ResidentialComplex;
use App\Models\Maps\Alias;
use App\Models\Maps\Country;
use App\Models\Maps\Department;
use App\Models\Maps\Municipality;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOneThrough;

class UserAddress extends Audit
{
    use HasFactory;

    protected $table = 'user_address';

    protected $primaryKey = 'address_id';

    public $timestamps = false;

    protected $fillable = [
        'user_id',
        'address',
        // Sin esto Eloquent lo descarta en silencio, igual que pasó con
        // `payment_state` en los pedidos.
        'street',
        // Dónde vive de verdad quien está en un conjunto. Antes esto era texto
        // libre dentro de `street`, si acaso, y no se podía filtrar nada.
        'complex_id',
        'tower',
        'apartment',
        'municipality_id',
        'alias_id',
        'latitude',
        'longitude',
        'state',
    ];

    public function complex()
    {
        return $this->belongsTo(
            ResidentialComplex::class,
            'complex_id',
            'complex_id',
        );
    }

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id', 'user_id');
    }

    /** @return BelongsTo<Municipality, $this> */
    public function municipality(): BelongsTo
    {
        return $this->belongsTo(Municipality::class, 'municipality_id', 'id');
    }

    /** @return BelongsTo<Alias, $this> */
    public function alias(): BelongsTo
    {
        return $this->belongsTo(Alias::class, 'alias_id', 'alias_id');
    }

    /** @return HasOneThrough<Department, Municipality, $this> */
    public function department(): HasOneThrough
    {
        return $this->hasOneThrough(
            Department::class,
            Municipality::class,
            'id',          // FK de municipality en user_address
            'id',          // FK de department en municipality
            'municipality_id', // Local key en user_address
            'department_id'   // Local key en municipality
        );
    }

    /**
     * EL PAÍS DE UNA DIRECCIÓN. NO FUNCIONA, Y SE DEJA ESCRITO.
     *
     * Su clave local es `department_id` y **`user_address` no tiene esa
     * columna**: solo tiene `municipality_id`. Así que esta relación devuelve
     * `null` siempre, y lo ha hecho desde que se escribió. Comprobado contra
     * los datos de desarrollo: direcciones cuyo municipio es «Barranquilla» y
     * cuyo departamento es «Atlántico» resuelven bien las dos y dan país
     * `null`, habiendo 27 países en la tabla.
     *
     * POR QUÉ NO SE ARREGLA AQUÍ. `PedidoDeLaAppResource` lleva ese `null` al
     * campo `country` de cada dirección de cada pedido, y lo llevan las dos
     * apps publicadas en las tiendas. Hacerlo resolver cambiaría un valor que
     * los clientes reciben como nulo desde siempre, y eso es una decisión de
     * producto, no una corrección de código: no se sabe qué hace la app con
     * un país que de repente llega lleno.
     *
     * Arreglarla es un salto más: `municipality_id → municipalities →
     * department_id → departments → country_id`. Son tres niveles, y
     * `hasOneThrough` solo cubre dos.
     *
     * MIENTRAS TANTO NO SE PRECARGA. Precargarla ejecutaba una consulta por
     * petición que no podía devolver nada, y el campo se rellena con `null`
     * escrito a mano, que es lo mismo que devolvía y se lee como lo que es.
     *
     * @deprecated Devuelve null siempre: ver arriba antes de usarla.
     */
    /** @return HasOneThrough<Country, Department, $this> */
    public function country(): HasOneThrough
    {
        return $this->hasOneThrough(
            Country::class,
            Department::class,
            'id',         // FK de department en municipality
            'id',         // FK de country en department
            'department_id', // Local key en department — NO EXISTE en user_address
            'country_id'     // Local key en country
        );
    }

    public function scopeActive($query)
    {
        return $query->where('state', true);
    }
}
