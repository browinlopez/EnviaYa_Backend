<?php

declare(strict_types=1);

namespace App\Http\Requests\User;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Una direccion nueva de la libreta de un usuario.
 */
class AgregarDireccionRequest extends FormRequest
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
            'user_id' => 'required|integer|exists:user,user_id',
            'address' => 'required|string|max:225',
            /*
             * La calle que escribe la persona. La app ya la mandaba y acá no se
             * miraba: se perdía en silencio.
             *
             * Es opcional porque la dirección del mapa por sí sola ya sirve
             * para pedir; pero cuando viene, es la que lleva el número de casa
             * y la que de verdad permite llegar a la puerta.
             */
            'street' => 'nullable|string|max:150',
            /*
             * Dónde vive quien está en un conjunto.
             *
             * Van como texto y no como número: hay conjuntos con "Torre A" y
             * apartamentos como "502B". Guardarlos como enteros obligaría a
             * inventar una traducción y perdería lo que la persona escribió.
             */
            'complex_id' => 'nullable|integer|exists:residential_complexes,complex_id',
            'tower' => 'nullable|string|max:40',
            'apartment' => 'nullable|string|max:40',
            /*
             * El municipio llega por NOMBRE, no por identificador.
             *
             * El teléfono resuelve las coordenadas a ciudad y departamento para
             * escribir la dirección en pantalla; no conoce nuestros ids. Antes
             * esto pedía `municipality_id` y estaba comentado, así que ninguna
             * dirección creada desde la app tenía municipio.
             *
             * Se sigue admitiendo el id por si algún día lo manda el panel.
             */
            'municipality' => 'nullable|string|max:120',
            'department' => 'nullable|string|max:120',
            'municipality_id' => 'nullable|integer|exists:municipalities,id',
            'alias_id' => 'nullable|integer|exists:alias,alias_id',
            'latitude' => 'nullable|numeric',
            'longitude' => 'nullable|numeric',
        ];
    }
}
