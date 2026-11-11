<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Crear una campana de pauta.
 *
 * EL METODO ESTATICO NO ES UN ADORNO. `CampanasApiController` tenia un ayudante
 * privado `validarCampana($request, $parcial)` llamado DOS veces: desde
 * `storeCampaign` con las reglas estrictas y desde `updateCampaign` con `sometimes`,
 * para que una correccion parcial no exija reenviar todo.
 *
 * `storeCampaign` se convierte y usa esta clase. **`updateCampaign` no se puede
 * convertir**: hace una busqueda que contesta 404 ANTES de validar, asi que un
 * FormRequest —que valida antes de entrar al metodo— cambiaria ese 404 por un
 * 422 hablando del cuerpo. Es un cambio de contrato, no una mejora.
 *
 * Asi que las reglas viven aqui, en UN sitio, y `updateCampaign` las pide en modo
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
class CampanaRequest extends FormRequest
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
            'advertiser_id' => "{$regla}|integer|exists:advertisers,id",
            'name' => "{$regla}|string|max:150",
            'description' => 'sometimes|nullable|string',
            'objective' => 'sometimes|in:awareness,traffic,conversion',
            'starts_at' => "{$regla}|date",
            // La fecha final nunca antes de la inicial: una campaña invertida
            // no falla al guardarse, simplemente no entrega nada, y eso se
            // descubre tarde y sin mensaje.
            'ends_at' => "{$regla}|date|after_or_equal:starts_at",
            'budget' => 'sometimes|numeric|min:0',
            'state' => 'sometimes|integer|in:0,1,2,3',
        ];
    }
}
