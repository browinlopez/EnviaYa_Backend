<?php

declare(strict_types=1);

namespace App\Http\Requests\Conjunto;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Pedir otras torres o apartamentos por torre. No cambia el conjunto: radica
 * la petición para que el equipo la revise.
 */
class SolicitarCambioDeFormaRequest extends FormRequest
{
    /* El permiso lo resuelve el middleware de la ruta (`conjunto:dueno`). */
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'towers_count' => 'required|integer|min:1|max:200',
            'apartments_per_tower' => 'required|integer|min:1|max:2000',
            'note' => 'nullable|string|max:1000',
        ];
    }

    public function attributes(): array
    {
        return [
            'towers_count' => 'torres',
            'apartments_per_tower' => 'apartamentos por torre',
            'note' => 'nota',
        ];
    }
}
