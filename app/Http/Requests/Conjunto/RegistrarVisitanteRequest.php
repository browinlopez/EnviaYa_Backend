<?php

declare(strict_types=1);

namespace App\Http\Requests\Conjunto;

use App\Http\Controllers\Conjunto\VisitantesController;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Anotar en la porteria quien entro: visita, servicio o domicilio de fuera.
 *
 * LAS REGLAS DE CAMPO SE MUEVEN AQUI Y LA OTRA COMPROBACION NO, y la
 * diferencia es la razon de que este fichero exista en vez de llevarse el
 * metodo entero.
 *
 * `VisitantesController::registrar()` hace dos cosas que parecen la misma: las
 * reglas de abajo, que solo mirán el cuerpo, y una segunda que pregunta a la
 * base si ESE conjunto exige dejar constancia de quien dio permiso
 * (`require_authorization`) y, si la exige y falta el nombre, contesta 422 con
 * un mensaje propio.
 *
 * Esa segunda se queda en el controlador. Traerla aqui —a `withValidator()` o
 * a una regla condicional— cambiaria el texto de la respuesta: hoy el panel
 * recibe «Este conjunto exige dejar constancia...» en `message` y «Falta
 * anotar quien dio permiso.» en `errors.authorized_by`, y una regla de
 * validacion normal pone el MISMO texto en los dos sitios. Son dos frases
 * distintas a proposito: una explica la regla del edificio y la otra dice que
 * escribir. Mover la regla habria costado una de las dos.
 *
 * El inventario de `scripts/validaciones_en_linea.py` tenia este metodo en
 * «HAY CODIGO ANTES: MIRARLO», y lo unico que habia antes era leer el
 * `complex_id` que deja el middleware: eso no necesita ir antes de validar.
 */
class RegistrarVisitanteRequest extends FormRequest
{
    /*
     * El permiso lo resuelve el middleware `conjunto` de la ruta, que es
     * donde vive en este backend. Repetirlo aqui seria una segunda fuente de
     * verdad sobre quien puede que, y la que se olvide de actualizar sera la
     * que mande.
     */
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, string> */
    public function rules(): array
    {
        return [
            // Las clases las declara el controlador: es su vocabulario, y
            // tenerlas dos veces es tenerlas mal en una de las dos.
            'kind' => 'required|string|in:'.implode(',', VisitantesController::CLASES),
            'visitor_name' => 'required|string|max:120',
            'visitor_document' => 'nullable|string|max:40',
            'visitor_phone' => 'nullable|string|max:40',
            'visitor_company' => 'nullable|string|max:120',
            'vehicle_plate' => 'nullable|string|max:20',
            'tower' => 'nullable|string|max:30',
            'apartment' => 'nullable|string|max:30',
            'authorized_by' => 'nullable|string|max:120',
            'notes' => 'nullable|string|max:1000',
        ];
    }
}
