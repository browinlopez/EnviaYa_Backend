<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Models\Operacion\SafetyIncident;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Registrar un incidente de seguridad.
 *
 * EL METODO ESTATICO NO ES UN ADORNO. `IncidentesApiController` tenia un ayudante
 * privado `validarIncidente($request, $parcial)` llamado DOS veces: desde
 * `storeIncidente` con las reglas estrictas y desde `updateIncidente` con `sometimes`,
 * para que una correccion parcial no exija reenviar todo.
 *
 * `storeIncidente` se convierte y usa esta clase. **`updateIncidente` no se puede
 * convertir**: hace una busqueda que contesta 404 ANTES de validar, asi que un
 * FormRequest —que valida antes de entrar al metodo— cambiaria ese 404 por un
 * 422 hablando del cuerpo. Es un cambio de contrato, no una mejora.
 *
 * Asi que las reglas viven aqui, en UN sitio, y `updateIncidente` las pide en modo
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
class IncidenteRequest extends FormRequest
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
            'domiciliary_id' => 'sometimes|nullable|integer|exists:domiciliary,domiciliary_id',
            'order_id' => 'sometimes|nullable|integer|exists:orderssales,orderSales_id',
            'occurred_at' => "{$regla}|date",
            'type' => "{$regla}|in:accidente_transito,caida,robo,agresion,falla_vehiculo,condicion_insegura,otro",
            'severity' => 'sometimes|in:leve,moderado,grave',
            'had_injuries' => 'sometimes|boolean',
            'days_off' => 'sometimes|integer|min:0|max:2000',
            'location' => 'sometimes|nullable|string|max:255',
            'description' => "{$regla}|string",
            'actions' => 'sometimes|nullable|string',
            'state' => 'sometimes|integer|in:'.implode(',', SafetyIncident::ESTADOS),
        ];
    }
}
