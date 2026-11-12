<?php

declare(strict_types=1);

namespace App\Http\Resources\Usuario;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Arr;

/**
 * UN NEGOCIO VINCULADO, DENTRO DEL LOGIN DEL TENDERO O DEL DOMICILIARIO. Y
 * aqui esta el hallazgo de la tanda.
 *
 * Es el `businesses` que cuelga de `owner` y de `domiciliary`. Se serializaba
 * entero: veintidos columnas, el `pivot` incluido. Entre ellas:
 *
 *   credit_debt_cap    el tope de credito de la tienda
 *   max_courier_cash   cuanto efectivo puede llevar encima su domiciliario
 *
 * Y `credit_debt_cap` esta documentado en este mismo repositorio, en
 * `NegocioRequest`, con estas palabras: «lo que la tienda puede fiar entre
 * todos sus clientes y la deuda que puede arrastrar con la plataforma. Solo lo
 * pone el equipo; EL PANEL DEL TENDERO NO LO RECIBE».
 *
 * Lo recibe. Y no solo el tendero: medido contra la respuesta real, el
 * DOMICILIARIO recibe `credit_debt_cap = 1000000.00` de cada negocio con el
 * que trabaja, junto con el NIT, la razon social y las coordenadas. El
 * domiciliario no tiene nada que hacer con el tope de credito de la tienda.
 *
 * SE CONSERVAN LAS VEINTIDOS, a proposito: quitar dos campos de la respuesta
 * del login cambia lo que reciben dos apps publicadas en las tiendas, y si
 * alguna pantalla los lee, quitarlos la rompe. Cual de las dos sobra —y a
 * quien -- es una decision de producto. Lo que se gana es que ahora la lista
 * esta escrita, medida y con prueba, y que una columna nueva de `business` ya
 * no se publica sola en el login de nadie.
 */
class NegocioVinculadoResource extends JsonResource
{
    /**
     * Lo que esta respuesta publica, sacado de la respuesta REAL del login.
     *
     * VEINTIDOS claves, y dos de ellas son el hallazgo: `credit_debt_cap` y `max_courier_cash`. Ver la cabecera.
     */
    private const CAMPOS = [
        'busines_id',
        'name',
        'phone',
        'address',
        'qualification',
        'razonSocial_DCD',
        'NIT',
        'logo',
        'description',
        'latitude',
        'longitude',
        'municipality_id',
        'type',
        'max_courier_cash',
        'credit_debt_cap',
        'state',
        'accepting_orders',
        'accepting_changed_at',
        'accepting_until',
        'created_at',
        'updated_at',
        'pivot',
    ];

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return Arr::only($this->resource->toArray(), self::CAMPOS);
    }
}
