<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Crear una campana de avisos push.
 *
 * EL METODO ESTATICO NO ES UN ADORNO. `MarketingApiController` tenia un ayudante
 * privado `validarPush($request, $parcial)` llamado DOS veces: desde
 * `storePushCampaign` con las reglas estrictas y desde `updatePushCampaign` con `sometimes`,
 * para que una correccion parcial no exija reenviar todo.
 *
 * `storePushCampaign` se convierte y usa esta clase. **`updatePushCampaign` no se puede
 * convertir**: hace una busqueda que contesta 404 ANTES de validar, asi que un
 * FormRequest —que valida antes de entrar al metodo— cambiaria ese 404 por un
 * 422 hablando del cuerpo. Es un cambio de contrato, no una mejora.
 *
 * Asi que las reglas viven aqui, en UN sitio, y `updatePushCampaign` las pide en modo
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
class CampanaPushRequest extends FormRequest
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
            'title' => "{$regla}|string|max:120",
            'body' => "{$regla}|string|max:500",
            'link_type' => 'sometimes|in:none,url,business,product,category',
            'link_value' => 'sometimes|nullable|string|max:500',
            'segment' => 'sometimes|nullable|array',
            'scheduled_at' => 'sometimes|nullable|date',
            'state' => 'sometimes|integer|in:0,1,3',
        ];
    }
}
