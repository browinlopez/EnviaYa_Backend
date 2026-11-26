<?php

declare(strict_types=1);

namespace App\Http\Requests\Conjunto;

use Illuminate\Foundation\Http\FormRequest;

/**
 * La ventana de fechas de los reportes del conjunto (pantalla y Excel).
 *
 * Una fecha que no es fecha es un 422 que dice cuál campo, no un 500 que
 * sale de `CarbonImmutable::parse()` sin explicar nada.
 */
class VentanaDelConjuntoRequest extends FormRequest
{
    /*
     * El permiso lo resuelve el middleware de la ruta, que es donde vive en
     * este backend. Repetirlo aqui seria una segunda fuente de verdad.
     */
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'desde' => 'nullable|date',
            'hasta' => 'nullable|date',
        ];
    }
}
