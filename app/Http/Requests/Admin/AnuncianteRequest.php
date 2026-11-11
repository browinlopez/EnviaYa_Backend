<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Crear un anunciante de la pauta.
 *
 * POR QUE ESTA CLASE TIENE UN METODO ESTATICO ADEMAS DE `rules()`, que es la
 * parte que hay que entender antes de copiarla:
 *
 * `AnunciantesApiController` tenia un ayudante privado
 * `validarAnunciante($request, $parcial)` llamado DOS veces: una desde
 * `storeAdvertiser` con las reglas estrictas y otra desde `updateAdvertiser`
 * con `sometimes`, para que una correccion parcial no exija reenviar todo.
 *
 * `storeAdvertiser` se convierte y usa esta clase. **`updateAdvertiser` no se
 * puede convertir**: hace `Advertiser::findOr($id, abort 404)` ANTES de
 * validar, asi que un `FormRequest` —que valida antes de entrar al metodo—
 * cambiaria el 404 de «ese anunciante no existe» por un 422 hablando del
 * cuerpo. Eso es un cambio de contrato, no una mejora.
 *
 * Asi que las reglas viven aqui, en un sitio, y `update` las pide en modo
 * parcial con `self::reglas(parcial: true)`. La alternativa era dejar las
 * reglas en el ayudante y tener dos copias: la que el FormRequest valida y la
 * que el ayudante valida. Dos copias de una regla es tenerla mal en una de
 * las dos, y ya paso en este repositorio con la lista de precargas.
 *
 * El mismo patron en los otros siete `validar*` de la API de administracion:
 * los ocho tenian store convertible y update con la busqueda delante. Eso lo
 * destapo comprobar los LLAMANTES uno por uno, porque
 * `scripts/validaciones_en_linea.py` clasificaba el AYUDANTE y no a quien lo
 * llama, y decia «se puede convertir» de un ayudante del que solo la mitad se
 * podia.
 */
class AnuncianteRequest extends FormRequest
{
    /*
     * El permiso lo resuelve el middleware `modulo:` de la ruta, que es donde
     * vive en este backend. Repetirlo aqui seria una segunda fuente de verdad
     * sobre quien puede que.
     */
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, string> */
    public function rules(): array
    {
        return self::reglas();
    }

    /**
     * Las reglas, estrictas o parciales.
     *
     * @return array<string, string>
     */
    public static function reglas(bool $parcial = false): array
    {
        $regla = $parcial ? 'sometimes' : 'required';

        return [
            'name' => "{$regla}|string|max:150",
            'business_id' => 'sometimes|nullable|integer|exists:business,busines_id',
            'contact_name' => 'sometimes|nullable|string|max:150',
            'contact_email' => 'sometimes|nullable|email|max:150',
            'contact_phone' => 'sometimes|nullable|string|max:30',
            'tax_id' => 'sometimes|nullable|string|max:40',
            'notes' => 'sometimes|nullable|string',
            'state' => 'sometimes|boolean',
        ];
    }
}
