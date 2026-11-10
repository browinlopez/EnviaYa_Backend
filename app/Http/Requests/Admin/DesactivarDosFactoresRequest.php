<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

/**
 * La contrasena, para apagar el segundo factor.
 *
 * Se pide la contrasena y no basta la sesion: apagar el segundo factor desde
 * una sesion robada seria quitarle la cerradura a la puerta desde dentro.
 */
class DesactivarDosFactoresRequest extends FormRequest
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
        return ['password' => 'required|string'];
    }
}
