<?php

declare(strict_types=1);

namespace App\Http\Requests\Dispositivos;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Olvidar el token de un telefono: deja de recibir avisos.
 */
class OlvidarTokenDelDispositivoRequest extends FormRequest
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
        return ['token' => 'required|string|max:255'];
    }
}
