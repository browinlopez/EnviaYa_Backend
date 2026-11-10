<?php

namespace App\Http\Requests\Admin;

use App\Support\CatalogoDeAjustes;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Guardar ajustes de la plataforma.
 */
class GuardarAjustesRequest extends FormRequest
{
    /*
     * El permiso lo resolvio el middleware de la ruta: `admin` y, donde
     * aplica, `modulo:<area>,gestionar`. Repetirlo aqui seria una segunda
     * fuente de verdad sobre quien puede que.
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
