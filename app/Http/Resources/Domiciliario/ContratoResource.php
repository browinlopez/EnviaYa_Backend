<?php

declare(strict_types=1);

namespace App\Http\Resources\Domiciliario;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * EL ACUERDO DEL DOMICILIARIO: para leerlo y para saber si ya lo firmo.
 *
 * Era el array a mano de `ContratoDelDomiciliarioController::mostrar()`.
 *
 * DOS DE LAS OCHO CLAVES NO SALEN DEL MODELO y por eso entran por el
 * constructor: `url` la arma el controlador con el enlace firmado del medio
 * guardado, y `texto` lo rinde `ContratosDeDomiciliarios::comoTexto()`. Es el
 * mismo patron que `ProductoDeLaFichaResource`, que recibe la afiliacion y el
 * cierre de promociones: lo que el Resource no puede saber por si mismo se le
 * pasa, en vez de darle acceso a los servicios del controlador.
 *
 * EL TIPO ES `object` Y NO `Domiciliary`, a proposito, y esto lo encontraron
 * las pruebas: `suyo()` no devuelve un modelo, devuelve el `stdClass` de un
 * `DB::table('domiciliary')->leftJoin('user')->first([...])`, porque hace
 * falta el nombre de la persona y vive en otra tabla. Puesto `Domiciliary`
 * en el constructor, los seis casos de `ContratoDesdeLaAppTest` dieron 500
 * con un TypeError.
 *
 * Es el caso que la cabecera de `scripts/formas_de_entidad.py` avisa que no
 * puede separar: un `$d->campo` tiene la misma forma venga de un modelo o de
 * una fila cruda, asi que «forma de entidad» no implica «hay un modelo
 * detras». Aqui el Resource solo escribe la forma, que es lo que hace falta.
 *
 * `falta_documento` esta a proposito y no es redundante con `documento`:
 * el acuerdo lleva la cedula impresa, asi que firmarlo sin ella no tiene
 * sentido. La pantalla lo pide cuando falta, en vez de dejar que el envio
 * reviente al final.
 */
class ContratoResource extends JsonResource
{
    public function __construct(
        object $domiciliario,
        private readonly ?string $url,
        /** @var list<array{tipo:string,texto:string}> El acuerdo rendido en secciones, tal como lo devuelve `ContratoService::comoTexto()`. */
        private readonly array $texto,
    ) {
        parent::__construct($domiciliario);
    }

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $d = $this->resource;

        return [
            'firmado' => $d->contract_signed_at !== null,
            'firmado_el' => $d->contract_signed_at,
            'ciudad' => $d->contract_city,
            'documento' => $d->document,
            'nombre' => $d->name,
            'falta_documento' => empty($d->document),
            'url' => $this->url,
            'texto' => $this->texto,
        ];
    }
}
