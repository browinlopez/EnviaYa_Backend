<?php

declare(strict_types=1);

namespace App\Http\Resources\Marketing;

use App\Models\Marketing\AdCampaign;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Arr;

/**
 * UNA CAMPANA DE PAUTA EN EL PANEL.
 *
 * Era el `array_merge($c->toArray(), [...])` de
 * `CampanasApiController::campaigns()`, con el mismo agujero que los banners:
 * la respuesta eran «las columnas de la tabla» mas las relaciones precargadas,
 * asi que una columna nueva se publicaba sola.
 *
 * `advertiser` SE CONSERVA. Viaja el anunciante entero porque el controlador
 * precarga esa relacion para sacar su nombre, y dentro van `contact_email`,
 * `contact_phone`, `tax_id` y las `notes` internas. El controlador extrae
 * ademas `advertiser_name`, que es lo que sugiere que el panel usa — pero
 * sugerir no es comprobar, asi que la clave se queda y quitarla es borrar una
 * linea de la lista de abajo.
 *
 * Las metricas —`banners_count`, `impressions`, `clicks`— se RECIBEN, no se
 * calculan aqui: salen de una consulta agregada que el controlador hace una
 * vez para todas las campanas. Calcularlas dentro del Resource serian tres
 * consultas por campana, que es el N+1 que esa consulta agregada existe para
 * evitar.
 */
class CampanaResource extends JsonResource
{
    /** Lo que esta respuesta publica, sacado de la respuesta real. */
    private const CAMPOS = [
        'id',
        'advertiser_id',
        'name',
        'description',
        'objective',
        'starts_at',
        'ends_at',
        'budget',
        'state',
        'created_at',
        'updated_at',
        // La relacion precargada. Ver la cabecera.
        'advertiser',
    ];

    public function __construct(
        AdCampaign $campana,
        private readonly int $banners,
        private readonly int $impresiones,
        private readonly int $clics,
    ) {
        parent::__construct($campana);
    }

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $c = $this->resource;

        return array_merge(Arr::only($c->toArray(), self::CAMPOS), [
            'advertiser_name' => $c->advertiser?->name,
            'banners_count' => $this->banners,
            'impressions' => $this->impresiones,
            'clicks' => $this->clics,
        ]);
    }
}
