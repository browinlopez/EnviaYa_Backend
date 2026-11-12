<?php

declare(strict_types=1);

namespace App\Http\Resources\Negocio;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Arr;

/**
 * UN TIPO DE NEGOCIO: tienda, droguería, panadería, licorera.
 *
 * `CategoryBusinessController::index()` devolvia `CategoryBusiness::all()`
 * tal cual: el modelo entero, como cuerpo de la respuesta, sin lista de
 * claves. Era el ultimo `Model::all()` del backend y tambien el ultimo sitio
 * que publicaba una tabla completa sin que nadie hubiera escrito su forma.
 *
 * Y LO QUE LO HACE DISTINTO DE LOS OTROS: una de las dos rutas que sirve este
 * metodo es PUBLICA.
 *
 *   GET /v1/categories-business/indexFree    throttle:300,1 y nada mas
 *   GET /v1/categories-business/index        auth:sanctum
 *
 * Asi que una columna nueva en `categories_business` --un peso para ordenar,
 * una comision por tipo de negocio, una nota del equipo-- no se publicaba
 * solo a quien tiene sesion: se publicaba a cualquiera, sin cuenta. Seis
 * claves, medidas contra una fila real, y las seis se conservan: son el
 * nombre, la descripcion y la imagen que la app pinta en la pantalla de
 * antes de entrar.
 *
 * DE PASO, UN HALLAZGO QUE NO SE ARREGLA AQUI. Las dos rutas de arriba
 * llaman a ESTE MISMO metodo y devuelven lo mismo, pero una pide sesion y la
 * otra no. La autenticacion de `index` no protege nada: el mismo dato esta
 * publico un camino mas alla. No es una fuga --este catalogo esta hecho para
 * verse sin cuenta-- pero una de las dos rutas sobra, y cual se retira es
 * una decision, no una limpieza: las dos salen en el codigo de los clientes.
 */
class CategoriaDeNegocioResource extends JsonResource
{
    /**
     * Lo que esta respuesta publica HOY, medido contra una fila real.
     *
     * Seis claves. `image` es la ruta del icono y sale tal cual, como hasta
     * ahora; `created_at` y `updated_at` se conservan porque ya viajaban y
     * quitarlas cambiaria lo que recibe la app publicada.
     */
    private const CAMPOS = [
        'id',
        'name',
        'description',
        'image',
        'created_at',
        'updated_at',
    ];

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return Arr::only($this->resource->toArray(), self::CAMPOS);
    }
}
