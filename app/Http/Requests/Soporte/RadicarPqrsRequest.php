<?php

namespace App\Http\Requests\Soporte;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Lo que la app manda al pedir ayuda: un PQRS.
 *
 * Las reglas estaban en línea en `AyudaDesdeLaAppController::store()` y se
 * mueven tal cual, sin retocar ni una: lo que entra y lo que se rechaza es
 * exactamente lo de antes. Lo fija
 * `tests/Feature/Soporte/AyudaDesdeLaAppTest.php`, que ya existía.
 *
 * El nombre, el correo y el teléfono NO se validan porque no viajan: salen de
 * la cuenta de quien escribe. Un reclamo que dice ser de otra persona no
 * sirve para nada.
 */
class RadicarPqrsRequest extends FormRequest
{
    /** Lo que la app ofrece elegir. «Felicitación» no: eso es una reseña. */
    private const TIPOS = 'peticion,queja,reclamo,sugerencia';

    /*
     * El permiso lo resuelve el middleware de la ruta, que es donde vive en
     * este backend: `/ayuda` va dentro del grupo autenticado y con su tope de
     * envíos. Repetirlo aquí sería una segunda fuente de verdad sobre quién
     * puede qué.
     */
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'type' => 'required|in:'.self::TIPOS,
            'subject' => 'required|string|max:200',
            'description' => 'required|string|max:4000',
            /*
             * El pedido es opcional —no todo reclamo es de un pedido— y
             * `exists` solo comprueba que el número sea de un pedido REAL.
             *
             * Que sea SUYO lo comprueba el controlador, y tiene que seguir
             * siendo así: ahí hace falta la sesión y una consulta a
             * `owner_busines` para saber si el tendero es dueño de la tienda
             * de ese pedido. Una regla de validación que devolviera 422 sobre
             * el pedido de otra persona además confirmaría que ese pedido
             * existe, que es justo lo que no se quiere decir.
             */
            'order_id' => 'sometimes|nullable|integer|exists:orderssales,orderSales_id',
        ];
    }
}
