<?php

declare(strict_types=1);

namespace App\Http\Requests\Seguridad;

use App\Models\Operacion\SafetyIncident;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Lo que el teléfono manda al soltar el botón de SOS.
 *
 * TODO ES OPCIONAL A PROPÓSITO, y es la regla más importante de este fichero.
 * El aviso sale mientras la persona todavía está en el problema: si el GPS no
 * ha fijado posición, si no le dio tiempo a elegir motivo, si la red sólo deja
 * pasar una petición mínima, el aviso TIENE que salir igual. Un 422 por un
 * campo que falta sería un SOS que no llegó por culpa de la validación.
 *
 * Lo que no se acepta es lo que no es suyo: el motivo se limita a los cuatro
 * que la hoja del teléfono ofrece (`MOTIVOS_SOS`), no al vocabulario entero de
 * la tabla, y la gravedad no viaja —entra siempre como «grave», porque eso es
 * lo que significa apretar tres segundos un botón rojo—.
 */
class LanzarSosRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            /*
             * Rangos reales, no `numeric` a secas: un 0,0 por defecto del
             * aparato mandaría a buscar a alguien al golfo de Guinea.
             */
            'latitude' => 'sometimes|nullable|numeric|between:-90,90',
            'longitude' => 'sometimes|nullable|numeric|between:-180,180',

            'type' => 'sometimes|nullable|in:'.implode(',', SafetyIncident::MOTIVOS_SOS),

            /* Por si alguna vez el botón manda contexto; hoy no manda nada. */
            'description' => 'sometimes|nullable|string|max:1000',
        ];
    }
}
