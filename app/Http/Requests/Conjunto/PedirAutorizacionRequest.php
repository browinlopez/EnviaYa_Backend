<?php

declare(strict_types=1);

namespace App\Http\Requests\Conjunto;

use App\Http\Controllers\Conjunto\VisitantesController;
use Illuminate\Foundation\Http\FormRequest;

// Torre y apartamento obligatorios: sin ellos no hay a quién preguntar.
class PedirAutorizacionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'visitor_name' => 'required|string|max:120',
            'kind' => 'nullable|string|in:'.implode(',', VisitantesController::CLASES),
            'tower' => 'required|string|max:30',
            'apartment' => 'required|string|max:30',
        ];
    }
}
