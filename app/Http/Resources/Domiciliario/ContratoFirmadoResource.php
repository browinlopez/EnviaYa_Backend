<?php

declare(strict_types=1);

namespace App\Http\Resources\Domiciliario;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * EL ACUERDO YA FIRMADO DE UN DOMICILIARIO, en el panel de administracion.
 *
 * Era el metodo `contrato()` de `DomiciliariosApiController`. Seis claves: el
 * archivo y las dos circunstancias de la firma.
 *
 * NO ES `ContratoResource`, que es el acuerdo que el domiciliario LEE en su
 * app antes de firmarlo. Aquella lleva el texto rendido y `falta_documento`
 * --si le falta la cedula para poder firmar--; esta lleva el fichero
 * guardado. Dos momentos del mismo tramite: antes y despues.
 *
 * DEVUELVE NULL EN DOS CASOS Y SON DISTINTOS, y por eso el nulo se decide en
 * el controlador y no aqui: que no haya firmado, y que el archivo se haya
 * borrado desde la seccion de documentos. En el segundo caso la ficha tiene
 * que decir que no hay contrato en vez de enlazar a un objeto muerto. Un
 * Resource que devolviera una forma vacia en ese caso cambiaria la respuesta
 * y, peor, taparia la diferencia.
 *
 * `url` se arma con `MediaService` en el momento de responder: es un enlace
 * firmado y caduca, asi que no se guarda ni se cachea.
 */
class ContratoFirmadoResource extends JsonResource
{
    public function __construct(
        object $archivo,
        private readonly string $url,
        private readonly mixed $firmadoEl,
        private readonly ?string $ciudad,
    ) {
        parent::__construct($archivo);
    }

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $a = $this->resource;

        return [
            'media_id' => (int) $a->id,
            'name' => $a->original_name,
            'size' => (int) $a->size_bytes,
            'url' => $this->url,
            'signed_at' => $this->firmadoEl,
            'city' => $this->ciudad,
        ];
    }
}
