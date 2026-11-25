<?php

declare(strict_types=1);

namespace App\Http\Resources\Usuario;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * UNA DIRECCION GUARDADA DEL COMPRADOR. Trece claves.
 *
 * Era el cierre `->map()` de `DireccionesController::getAddresses()`: la
 * lista de «Mis direcciones», que la app usa para elegir a donde va el
 * pedido.
 *
 * MANDA LOS IDS JUNTO A LOS NOMBRES --`municipality_id` y
 * `municipality_name`, y lo mismo con departamento y pais-- porque esta lista
 * no es para leer, es para EDITAR: la pantalla de corregir una direccion
 * necesita el identificador para preseleccionar el desplegable. La forma que
 * va DENTRO de un pedido es la contraria: solo nombres, porque ahi la
 * direccion ya no se cambia.
 *
 * Y AHI ESTA LA DIFERENCIA QUE IMPORTA, medida contra los datos de verdad:
 *
 *   esta lista                recorre `municipality->department->country` a
 *                             mano, y el pais SALE
 *   delivery_address de un    usa `UserAddress::country()`, una
 *   pedido                    `hasOneThrough` marcada `@deprecated Devuelve
 *                             null siempre` en el propio modelo
 *
 * Medido sobre el historial de un comprador: `delivery_address.country` es
 * NULA en 683 de 683 pedidos. El municipio y el departamento si salen; el
 * pais no ha salido nunca.
 *
 * No se arregla aqui: pasar de `null` a «Colombia» cambia lo que recibe una
 * app publicada, y una pantalla que hoy no pinta nada podria empezar a pintar
 * una linea. Queda medido y con prueba.
 */
class DireccionResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $a = $this->resource;

        return [
            'address_id' => $a->address_id,
            'address' => $a->address,
            // La calle y el número que escribió la persona, junto a la del mapa.
            'street' => $a->street,
            // El nombre propio, si se lo dio («Donde mi mamá»).
            'nombre' => $a->nombre,
            'latitude' => $a->latitude,
            'longitude' => $a->longitude,
            'alias_id' => $a->alias?->alias_id,
            'alias_name' => $a->alias?->name,
            'municipality_id' => $a->municipality?->id,
            'municipality_name' => $a->municipality?->name,
            'department_id' => $a->municipality?->department?->id,
            'department_name' => $a->municipality?->department?->name,
            /*
             * Por la CADENA y no por `UserAddress::country()`: esa relacion
             * esta rota y su propio docbloque lo dice. Ver la cabecera.
             */
            'country_id' => $a->municipality?->department?->country?->id,
            'country_name' => $a->municipality?->department?->country?->name,
            'country_iso_code' => $a->municipality?->department?->country?->iso_code,
        ];
    }
}
