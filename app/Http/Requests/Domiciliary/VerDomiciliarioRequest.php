<?php

declare(strict_types=1);

namespace App\Http\Requests\Domiciliary;

use Illuminate\Foundation\Http\FormRequest;

/**
 * La ficha de un domiciliario.
 */
class VerDomiciliarioRequest extends FormRequest
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

    /**
     * EL IDENTIFICADOR ES OPCIONAL, y el cambio es a propósito.
     *
     * Era `required`. Lo que hace el controlador si no viene es resolver el
     * domiciliario de LA SESIÓN, que es lo que debió hacer siempre: el cliente
     * no tiene por qué mandar un número que el servidor ya sabe, y mientras
     * tuvo que mandarlo nada comprobaba que fuera el suyo —un comprador
     * mandaba cualquiera y recibía la ficha entera—.
     *
     * `sometimes` y no `nullable`: si la clave viene, tiene que traer un
     * entero que exista. Con `nullable`, un `domiciliary_id: null` explícito
     * pasaría la validación y caería en el camino de la sesión, que es
     * confundir «no lo mando» con «lo mando vacío».
     */
    public function rules(): array
    {
        return [
            'domiciliary_id' => 'sometimes|integer|exists:domiciliary,domiciliary_id',
        ];
    }
}
