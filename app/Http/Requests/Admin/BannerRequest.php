<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Crear una pieza de pauta.
 *
 * EL METODO ESTATICO NO ES UN ADORNO. `BannersApiController` tenia un ayudante
 * privado `validarBanner($request, $parcial)` llamado DOS veces: desde
 * `storeBanner` con las reglas estrictas y desde `updateBanner` con `sometimes`,
 * para que una correccion parcial no exija reenviar todo.
 *
 * `storeBanner` se convierte y usa esta clase. **`updateBanner` no se puede
 * convertir**: hace una busqueda que contesta 404 ANTES de validar, asi que un
 * FormRequest —que valida antes de entrar al metodo— cambiaria ese 404 por un
 * 422 hablando del cuerpo. Es un cambio de contrato, no una mejora.
 *
 * Asi que las reglas viven aqui, en UN sitio, y `updateBanner` las pide en modo
 * parcial. La alternativa era dejarlas en el ayudante y tener dos copias: la
 * que valida el FormRequest y la que valida el ayudante. Dos copias de una
 * regla es tenerla mal en una de las dos.
 *
 * Los ocho `validar*` de la API de administracion tenian esta misma forma:
 * store convertible y update con la busqueda delante. Lo destapo comprobar
 * los LLAMANTES uno por uno, porque `scripts/validaciones_en_linea.py`
 * clasificaba el AYUDANTE y no a quien lo llama, y decia «se puede convertir»
 * de un ayudante del que solo la mitad se podia.
 */
class BannerRequest extends FormRequest
{
    /*
     * El permiso lo resuelve el middleware `modulo:` de la ruta, que es donde
     * vive en este backend.
     */
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return self::reglas();
    }

    /**
     * Las reglas, estrictas o parciales.
     *
     * @return array<string, mixed>
     */
    public static function reglas(bool $parcial = false): array
    {
        $regla = $parcial ? 'sometimes' : 'required';

        return [
            'campaign_id' => "{$regla}|integer|exists:ad_campaigns,id",
            'title' => "{$regla}|string|max:150",
            'subtitle' => 'sometimes|nullable|string|max:255',
            'placement' => 'sometimes|in:home_hero,home_strip,listing_inline,splash,web_home',
            'platform' => 'sometimes|in:app,web,both',
            'link_type' => 'sometimes|in:none,url,business,product,category',
            'link_value' => 'sometimes|nullable|string|max:500',
            'priority' => 'sometimes|integer|min:0|max:999',
            'starts_at' => 'sometimes|nullable|date',
            'ends_at' => 'sometimes|nullable|date|after_or_equal:starts_at',

            // Segmentación. Se admiten arreglos de enteros; vacío = sin filtro.
            'target_municipalities' => 'sometimes|nullable|array',
            'target_municipalities.*' => 'integer',
            'target_complexes' => 'sometimes|nullable|array',
            'target_complexes.*' => 'integer',
            'target_business_categories' => 'sometimes|nullable|array',
            'target_business_categories.*' => 'integer',
            'target_roles' => 'sometimes|nullable|array',
            'target_roles.*' => 'integer',

            'state' => 'sometimes|boolean',
        ];
    }
}
