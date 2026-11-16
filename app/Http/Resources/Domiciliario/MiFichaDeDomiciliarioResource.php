<?php

declare(strict_types=1);

namespace App\Http\Resources\Domiciliario;

use App\Http\Resources\Usuario\UsuarioResource;
use App\Models\Domiciliary;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Arr;

/**
 * LA FICHA DE UN DOMICILIARIO, PARA ÉL MISMO O PARA SU TIENDA.
 *
 * Es la respuesta de `POST /v1/domiciliaries/showDomiciliary`, y devolvía el
 * modelo pelado: `response()->json($domiciliary)`. O sea que la forma era «las
 * columnas que tengan `domiciliary`, `user` y `domiciliary_reviews`».
 *
 * MEDIDO ANTES DE TOCAR NADA, contra la pila HTTP de verdad: 12 claves en la
 * raíz, 20 en `user` y 8 por reseña. Esta clase publica EXACTAMENTE esas, y
 * hay una prueba que lo compara contra el `toArray()` del modelo para que no
 * sea una afirmación: `LaFichaDelDomiciliarioEsSuyaTest`.
 *
 * LO QUE CAMBIA, ENTONCES, NO ES LA FORMA: es que la lista está escrita. Una
 * migración que añada una columna a cualquiera de esas tres tablas ya no la
 * convierte en campo público de la API sin que nadie lo decida. Y en estas
 * tres tablas eso importa más que en otras, porque entre lo que ya hay está
 * la cédula del domiciliario y el token con el que se verifica su correo.
 *
 * EL `user` PASA POR `UsuarioResource`, que es la lista blanca que el
 * repositorio ya tenía escrita para la entidad más sensible del sistema. Hoy
 * da las mismas 20 claves —usa `Arr::only` sobre `toArray()`, así que conserva
 * los `casts` y el formato de fecha que las apps leen— y mañana crece cuando
 * crezca esa decisión, en un solo sitio.
 *
 * POR QUÉ `Arr::only` Y NO LOS CAMPOS A MANO, aquí también: `toArray()` del
 * modelo aplica los `casts` y serializa las fechas como lo hace Eloquent.
 * Escribir `'contract_signed_at' => $d->contract_signed_at` cambiaría el
 * formato de la fecha, que es justo lo que no se puede cambiar.
 */
class MiFichaDeDomiciliarioResource extends JsonResource
{
    /**
     * Las diez columnas de `domiciliary`, medidas de la respuesta real.
     *
     * `document` y `contract_*` se CONSERVAN: quien llega hasta acá es el
     * propio domiciliario o el dueño de una tienda a la que reparte, y la
     * tienda es quien le guarda los papeles. Lo que cerró la fuga fue la
     * autorización, no quitar campos; ver `DomiciliaryController`.
     */
    private const CAMPOS = [
        'domiciliary_id',
        'user_id',
        'available',
        'document',
        'qualification',
        'state',
        'contract_media_id',
        'contract_signed_at',
        'contract_city',
        'registrado_por_negocio',
    ];

    /** Las ocho de cada reseña, también de la respuesta real. */
    private const CAMPOS_DE_RESENA = [
        'reviews_id',
        'domiciliary_id',
        'buyer_id',
        'qualification',
        'comment',
        'state',
        'created_at',
        'updated_at',
    ];

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var Domiciliary $d */
        $d = $this->resource;

        $datos = Arr::only($d->toArray(), self::CAMPOS);

        /*
         * Solo las relaciones que de verdad estén cargadas, por lo mismo que
         * en `UsuarioResource`: tocar una relación no cargada dispara una
         * consulta, y con `preventLazyLoading` activo fuera de producción
         * directamente lanza. El controlador las carga las dos.
         */
        if ($d->relationLoaded('user') && $d->user !== null) {
            $datos['user'] = (new UsuarioResource($d->user))->resolve();
        }

        if ($d->relationLoaded('reviews')) {
            $datos['reviews'] = $d->reviews
                ->map(fn ($r) => Arr::only($r->toArray(), self::CAMPOS_DE_RESENA))
                ->values()
                ->all();
        }

        return $datos;
    }
}
