<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Destacar un negocio en la vitrina.
 *
 * EL METODO ESTATICO NO ES UN ADORNO. `DestacadosApiController` tenia un ayudante
 * privado `validarDestacado($request, $parcial)` llamado DOS veces: desde
 * `storeFeatured` con las reglas estrictas y desde `updateFeatured` con `sometimes`,
 * para que una correccion parcial no exija reenviar todo.
 *
 * `storeFeatured` se convierte y usa esta clase. **`updateFeatured` no se puede
 * convertir**: hace una busqueda que contesta 404 ANTES de validar, asi que un
 * FormRequest —que valida antes de entrar al metodo— cambiaria ese 404 por un
 * 422 hablando del cuerpo. Es un cambio de contrato, no una mejora.
 *
 * Asi que las reglas viven aqui, en UN sitio, y `updateFeatured` las pide en modo
 * parcial. La alternativa era dejarlas en el ayudante y tener dos copias: la
 * que valida el FormRequest y la que valida el ayudante. Dos copias de una
 * regla es tenerla mal en una de las dos.
 *
 * Los ocho `validar*` de la API de administracion tenian esta misma forma:
 * store convertible y update con la busqueda delante. Lo destapo comprobar
 * los LLAMANTES uno por uno, porque `scripts/validaciones_en_linea.py`
 * clasificaba el AYUDANTE y no a quien lo llama, y decia «se puede convertir»
 * de un ayudante del que solo la mitad se podia.
 */
class DestacadoRequest extends FormRequest
{
    /*
     * El permiso lo resuelve el middleware `modulo:` de la ruta, que es donde
     * vive en este backend.
     */
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return self::reglas();
    }

    /**
     * Las reglas, estrictas o parciales.
     *
     * @return array<string, mixed>
     */
    public static function reglas(bool $parcial = false): array
    {
        $regla = $parcial ? 'sometimes' : 'required';

        return [
            'business_id' => "{$regla}|integer|exists:business,busines_id",
            'placement' => 'sometimes|in:home_top,category_top,search_top',
            'priority' => 'sometimes|integer|min:0|max:999',
            'starts_at' => "{$regla}|date",
            'ends_at' => "{$regla}|date|after_or_equal:starts_at",
            'paid_amount' => 'sometimes|numeric|min:0',
            'state' => 'sometimes|boolean',
        ];
    }
}
