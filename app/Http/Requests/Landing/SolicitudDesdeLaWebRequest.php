<?php

namespace App\Http\Requests\Landing;

use App\Models\Operacion\LandingRequest;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Una solicitud enviada desde la web publica.
 */
class SolicitudDesdeLaWebRequest extends FormRequest
{
    /*
     * Esta ruta es PUBLICA: la escribe quien visita la web, sin cuenta. La
     * protege el limitador de peticiones de la ruta, no un permiso.
     */
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'nombre' => 'nullable|string|max:150',
            'contacto' => 'required|string|max:150',
            'barrio' => 'nullable|string|max:150',
            'mensaje' => 'nullable|string|max:5000',
            'tipo' => 'nullable|string|max:30',
            'motivo' => 'nullable|string|max:60',
            'origen' => 'nullable|string|max:40',
            'pagina' => 'nullable|string|max:120',
            'consentimiento' => 'accepted',
            'politica_version' => 'nullable|string|max:20',
            'aceptado_en' => 'nullable|date',
            // La foto o el comprobante de una PQRS. Llega en multipart; el
            // resto de formularios sigue mandando JSON.
            'adjunto' => 'nullable|file|max:'.LandingRequest::ADJUNTO_MAX_KB
                .'|mimes:'.LandingRequest::ADJUNTO_TIPOS,
        ];
    }

    public function messages(): array
    {
        return [
            'consentimiento.accepted' => 'Falta la autorización de tratamiento de datos.',
            'adjunto.max' => 'El archivo pesa más de 8 MB.',
            'adjunto.mimes' => 'Sube una foto (JPG, PNG, WEBP, HEIC) o un PDF.',
        ];
    }
}
