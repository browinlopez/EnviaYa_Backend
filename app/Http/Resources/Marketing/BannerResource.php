<?php

declare(strict_types=1);

namespace App\Http\Resources\Marketing;

use App\Models\Marketing\Banner;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Arr;

/**
 * UNA PIEZA DE PAUTA EN EL PANEL.
 *
 * Era `BannersApiController::presentarBanner()`, que hacia
 * `array_merge($b->toArray(), [...])`. Esa forma tiene dos agujeros, y el
 * motivo de esta clase es taparlos sin cambiar la respuesta:
 *
 * 1. `toArray()` publica TODAS LAS COLUMNAS de la tabla. Anadir una columna a
 *    `banners` la convertia en un campo publico de la API sin que nadie lo
 *    decidiera. Ahora la lista de campos esta escrita, y una columna nueva no
 *    aparece hasta que alguien la ponga aqui.
 *
 * 2. `toArray()` publica TAMBIEN LAS RELACIONES PRECARGADAS. El controlador
 *    hace `with('campaign.advertiser')` porque necesita los nombres, y eso
 *    mete en cada banner la campaña entera y, dentro, el anunciante entero:
 *    `contact_email`, `contact_phone`, `contact_name`, `tax_id` y las `notes`
 *    internas. Medido contra la respuesta real.
 *
 *    O sea que la forma de la respuesta dependia de que relaciones hiciera
 *    falta precargar para la CONSULTA. Cambiar un `with()` por rendimiento
 *    cambiaba la API.
 *
 * LA CLAVE `campaign` SE CONSERVA TAL CUAL, con su anunciante dentro. El
 * panel puede estar leyendola —`campaign_name` y `advertiser_name` sugieren
 * que no, porque si leyera el objeto no haria falta extraer los nombres, pero
 * «sugiere» no es «se comprobo»—. Quitarla es una decision de producto de un
 * minuto: borrar su linea de la lista de abajo. Lo que ya no puede pasar es
 * que crezca sola.
 *
 * POR QUE `Arr::only($b->toArray(), ...)` Y NO LOS CAMPOS A MANO. Escribir
 * `'starts_at' => $b->starts_at` cambiaria el formato de las fechas: el
 * `toArray()` del modelo aplica sus `casts` y serializa las fechas como las
 * serializa Eloquent, y las apps y el panel leen ESE formato. Una lista
 * blanca sobre la serializacion del modelo deja los valores exactos y hace
 * explicitas las claves, que es lo unico que faltaba.
 */
class BannerResource extends JsonResource
{
    /**
     * Las columnas y relaciones que esta respuesta publica, y ninguna mas.
     *
     * Sale de la respuesta REAL del endpoint, no del esquema de la tabla: lo
     * que hay que conservar es lo que el panel recibe hoy.
     */
    private const CAMPOS = [
        'id',
        'campaign_id',
        'title',
        'subtitle',
        'placement',
        'platform',
        'link_type',
        'link_value',
        'priority',
        'starts_at',
        'ends_at',
        'state',
        'impressions_count',
        'clicks_count',
        'target_roles',
        'target_municipalities',
        'target_complexes',
        'target_business_categories',
        'created_at',
        'updated_at',
        // La relacion precargada, con el anunciante dentro. Ver la cabecera.
        'campaign',
    ];

    /** @param  array<int, string|null>  $imagenes  la imagen principal por id de banner */
    public function __construct(
        Banner $banner,
        private readonly array $imagenes,
    ) {
        parent::__construct($banner);
    }

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $b = $this->resource;

        return array_merge(Arr::only($b->toArray(), self::CAMPOS), [
            'image_url' => $this->imagenes[$b->id] ?? null,
            'campaign_name' => $b->campaign?->name,
            'advertiser_name' => $b->campaign?->advertiser?->name,
            'ctr' => $b->impressions_count > 0
                ? round($b->clicks_count / $b->impressions_count * 100, 2)
                : 0,
        ]);
    }
}
