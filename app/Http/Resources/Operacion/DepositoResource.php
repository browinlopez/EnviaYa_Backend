<?php

declare(strict_types=1);

namespace App\Http\Resources\Operacion;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Arr;

/**
 * UNA CONSIGNACION DE EFECTIVO, la que devuelve confirmarla o rechazarla.
 *
 * `EfectivoController::resolverDeposito()` devolvia `'deposit' => $deposito`:
 * el modelo que acaba de devolver el servicio de custodia, entero y sin lista
 * de claves. La forma de la respuesta era «las columnas que tenga
 * `cash_deposits`», asi que una migracion que añadiera un campo lo publicaba
 * sola.
 *
 * Es la tabla donde se anota que un domiciliario entrego el efectivo que
 * llevaba encima: cuanto, cuando, por que canal, con que comprobante y quien
 * lo confirmo. Justo la clase de tabla a la que se le añade una columna
 * —una marca de conciliacion, una referencia del banco— sin pensar en quien
 * la recibe.
 *
 * SE CONSERVAN LAS QUINCE, medidas contra una fila real: la pantalla de
 * tesoreria lee el deposito completo despues de resolverlo, y recortar la
 * lista cambiaria lo que recibe el panel.
 */
class DepositoResource extends JsonResource
{
    /**
     * Lo que esta respuesta publica HOY, medido contra una fila real.
     *
     * Quince claves. `receipt_path` es la ruta del comprobante que subio el
     * domiciliario y sale tal cual, como hasta ahora.
     */
    private const CAMPOS = [
        'id',
        'domiciliary_id',
        'canal',
        'busines_id',
        'settlement_id',
        'amount',
        'reference',
        'deposited_at',
        'receipt_path',
        'state',
        'confirmed_by',
        'confirmed_at',
        'notes',
        'created_at',
        'updated_at',
    ];

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return Arr::only($this->resource->toArray(), self::CAMPOS);
    }
}
