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
        return ['password' => 'required|string'];
    }
}
