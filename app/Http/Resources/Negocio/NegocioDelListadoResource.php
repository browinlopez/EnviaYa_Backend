<?php

declare(strict_types=1);

namespace App\Http\Resources\Negocio;

use App\Models\Business;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * UN NEGOCIO EN UN LISTADO DE LA APP. Veintidós claves, o veintitrés.
 *
 * Esta forma estaba DOS VECES en `ConsultaDeNegociosController`, a ciento
 * setenta líneas una de otra: en `index()` —el listado por cercanía— y en
 * `indexByQualification()` —el de mejor calificadas, que además es público—.
 *
 * Y comparadas línea a línea con los comentarios quitados, las dos difieren
 * en EXACTAMENTE DOS COSAS. Las otras veintidós claves son las mismas
 * expresiones en el mismo orden:
 *
 *   `description`   solo en el listado por calificación, justo tras `address`
 *   `products`      `ProductoDelNegocioResource` en uno (doce campos) y
 *                   `ProductoDestacadoResource` en el otro (siete)
 *
 * Por eso los dos parámetros del constructor son exactamente esos dos, y no
 * una lista de opciones: cada uno corresponde a una diferencia medida.
 *
 * LO QUE ESTA CLASE NO ARREGLA, Y AHORA SE VE. La diferencia de los productos
 * —doce campos contra siete— es un asunto de producto sin decidir, y estaba
 * escrita en un comentario a mitad del controlador. Al pasar los productos ya
 * resueltos, la decisión queda en el controlador y a la vista de quien lea
 * las dos llamadas una debajo de otra, en vez de enterrada en un `return` de
 * cincuenta líneas. La cuenta exacta de qué falta en cuál está en la cabecera
 * de `ProductoDestacadoResource`.
 *
 * NO ES `NegocioDeLaResenaResource` --diez claves-- ni la ficha del negocio
 * --diecisiete y un bloque de promociones--. Tres formas del mismo negocio
 * para tres pantallas, y cada clase dice de quién es.
 *
 * `logo` lleva un valor por omisión que es un `example.com`. Se conserva tal
 * cual: es lo que reciben hoy las apps publicadas, y cambiarlo por `null`
 * obligaría a cada pantalla a defenderse de un hueco donde hoy hay una
 * cadena. Que ese valor sea un ejemplo de mentira es un arreglo aparte.
 */
class NegocioDelListadoResource extends JsonResource
{
    /**
     * @param  array{distance_km: float|null, delivery_fee: mixed, delivery_time_minutes: mixed, in_range: mixed}  $reparto
     *                                                                                                                       Lo que se calcula por negocio, porque cada tienda está en un sitio.
     * @param  array<int, mixed>  $productos  Ya resueltos: la CLASE cambia entre los dos listados.
     * @param  array<int, mixed>  $resenas  Ya resueltas, o `[]` en modo ligero.
     */
    public function __construct(
        Business $negocio,
        private readonly array $reparto,
        private readonly array $productos,
        private readonly array $resenas,
        private readonly bool $estaAfiliado,
        private readonly bool $conDescripcion,
    ) {
        parent::__construct($negocio);
    }

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var Business $b */
        $b = $this->resource;

        return [
            'business_id' => $b->busines_id,
            // Para pintarlos en el mapa cuando no hay ninguno cerca.
            'latitude' => $b->latitude !== null ? (float) $b->latitude : null,
            'longitude' => $b->longitude !== null ? (float) $b->longitude : null,
            'distance_km' => $this->reparto['distance_km'],
            'delivery_fee' => $this->reparto['delivery_fee'],
            // Lo que tarda desde ESTA tienda: la de la esquina no puede
            // anunciar el mismo plazo que la del otro barrio.
            'delivery_time_minutes' => $this->reparto['delivery_time_minutes'],
            'in_range' => $this->reparto['in_range'],
            'name' => $b->name,
            'phone' => $b->phone,
            'address' => $b->address,

            /* La primera de las dos diferencias. El spread conserva el orden:
               `description` va justo entre `address` y `qualification`, que es
               donde estaba en el listado por calificación. Fuera del spread,
               una clave condicional se iría al final y cambiaría el orden del
               JSON sin cambiar su contenido. */
            ...($this->conDescripcion ? ['description' => $b->description] : []),

            'qualification' => (float) $b->qualification,
            'razon_social' => $b->razonSocial_DCD,
            'NIT' => $b->NIT,
            'logo' => $b->logo ?? 'https://example.com/default-logo.png',
            'state' => (bool) $b->state,
            'accepting_orders' => (bool) $b->accepting_orders,
            'type' => $b->type,
            'municipality' => $b->municipality ? [
                'id' => $b->municipality->id,
                'name' => $b->municipality->name,
            ] : null,
            'owner_count' => $b->owners->count(),
            /*
             * DEL PROPIETARIO SOLO SALE LO QUE HACE FALTA PARA COMPRAR.
             *
             * Acá salían también su número de documento, su fecha de
             * nacimiento, su teléfono secundario y las notas internas del
             * equipo. Nada de eso lo usa la app —solo lee `user_id`, para
             * abrir el chat— y el listado por calificación es PÚBLICO:
             * cualquiera sin cuenta podía listar la cédula de todos los
             * tenderos aliados.
             */
            'owners' => PropietarioResource::collection($b->owners)->resolve(),
            /*
             * Las claves siguen viajando, vacías, en modo ligero: la app las
             * lee sin comprobar si existen, y quitarlas del todo la obligaría
             * a defenderse de un `undefined` en cada pantalla.
             */
            'products' => $this->productos,
            'reviews' => $this->resenas,
            'is_affiliated' => $this->estaAfiliado,
        ];
    }
}
