<?php

declare(strict_types=1);

namespace App\Http\Requests\Marketing;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Los banners que tocan en un sitio de la pantalla, para quien pregunta.
 */
class BannersParaLaAppRequest extends FormRequest
{
    /*
     * El permiso lo resuelve el middleware de la ruta, que es donde vive en
     * este backend. Repetirlo aqui seria una segunda fuente de verdad sobre
     * quien puede que, y la que se olvide de actualizar sera la que mande.
     */
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'placement' => 'required|in:home_hero,home_strip,listing_inline,splash,web_home',
            'platform' => 'sometimes|in:app,web',
            'municipality_id' => 'sometimes|nullable|integer',
            'complex_id' => 'sometimes|nullable|integer',
            'business_category_id' => 'sometimes|nullable|integer',
            'limit' => 'sometimes|integer|min:1|max:20',
        ];
    }
}
