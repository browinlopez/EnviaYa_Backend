<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Cargar un documento de un domiciliario.
 *
 * EL METODO ESTATICO NO ES UN ADORNO. `DocumentosApiController` tenia un ayudante
 * privado `validarDocumento($request, $parcial)` llamado DOS veces: desde
 * `storeDocumento` con las reglas estrictas y desde `updateDocumento` con `sometimes`,
 * para que una correccion parcial no exija reenviar todo.
 *
 * `storeDocumento` se convierte y usa esta clase. **`updateDocumento` no se puede
 * convertir**: hace una busqueda que contesta 404 ANTES de validar, asi que un
 * FormRequest —que valida antes de entrar al metodo— cambiaria ese 404 por un
 * 422 hablando del cuerpo. Es un cambio de contrato, no una mejora.
 *
 * Asi que las reglas viven aqui, en UN sitio, y `updateDocumento` las pide en modo
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
class DocumentoRequest extends FormRequest
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
            'domiciliary_id' => "{$regla}|integer|exists:domiciliary,domiciliary_id",
            'type' => "{$regla}|in:licencia,soat,tecnomecanica,arl,eps,examen_medico,cedula,otro",
            'number' => 'sometimes|nullable|string|max:60',
            'issued_at' => 'sometimes|nullable|date',
            // Puede ir nula: la cédula no caduca. Un documento sin fecha
            // simplemente no entra en las alertas.
            'expires_at' => 'sometimes|nullable|date',
            'media_id' => 'sometimes|nullable|integer|exists:media_files,id',
            'notes' => 'sometimes|nullable|string|max:255',
            'state' => 'sometimes|boolean',
        ];
    }
}
