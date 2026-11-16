<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Support\CatalogoDeAjustes;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Guardar ajustes de la plataforma.
 */
class GuardarAjustesRequest extends FormRequest
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
        return CatalogoDeAjustes::reglas();
    }
}
