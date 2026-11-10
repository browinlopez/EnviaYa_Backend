<?php

namespace App\Http\Requests\Negocio;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Un abono del cliente a lo que debe.
 */
class AbonarAlFiadoRequest extends FormRequest
{
    /*
     * El permiso ya lo resolvio el middleware `negocio`: puso en la peticion
     * el negocio de la sesion y piso lo que mandara el cliente. Repetirlo aca
     * seria una segunda fuente de verdad sobre quien puede que.
     */
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'amount' => 'required|numeric|min:1|max:999999999',
            'notes' => 'nullable|string|max:255',
            // La foto de la transferencia o del recibo. Opcional.
            'comprobante' => 'nullable|file|mimes:jpg,jpeg,png,webp,pdf|max:8192',
        ];
    }

    public function messages(): array
    {
        return [
            'comprobante.mimes' => 'El comprobante tiene que ser una foto (JPG, PNG o WEBP) o un PDF.',
            'comprobante.max' => 'El comprobante pesa mas de 8 MB.',
        ];
    }
}
