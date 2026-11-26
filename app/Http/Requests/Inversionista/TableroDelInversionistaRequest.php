<?php

declare(strict_types=1);

namespace App\Http\Requests\Inversionista;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Los filtros del tablero del inversionista. Todos opcionales: sin ninguno,
 * los últimos 30 días de toda la plataforma.
 */
class TableroDelInversionistaRequest extends FormRequest
{
    /*
     * El permiso lo resuelve el middleware de la ruta (`inversionista`), que
     * es donde vive en este backend.
     */
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'desde' => 'nullable|date',
            'hasta' => 'nullable|date',
            'municipality_id' => 'nullable|integer|min:1',
            // `business.type`, es decir, `category_business.id`.
            'tipo' => 'nullable|integer|min:1',
        ];
    }
}
