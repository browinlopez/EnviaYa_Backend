<?php

declare(strict_types=1);

namespace App\Http\Requests\Negocio;

use Illuminate\Foundation\Http\FormRequest;

/**
 * El logo de la tienda.
 */
class SubirLogoRequest extends FormRequest
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
            'logo' => 'required|image|mimes:jpg,jpeg,png,webp|max:4096',
        ];
    }

    public function messages(): array
    {
        return [
            'logo.required' => 'Elige la imagen del logo.',
            'logo.image' => 'El logo tiene que ser una imagen (JPG, PNG o WEBP).',
            'logo.mimes' => 'El logo tiene que ser una imagen (JPG, PNG o WEBP).',
            'logo.max' => 'La imagen pesa más de 4 MB.',
        ];
    }
}
