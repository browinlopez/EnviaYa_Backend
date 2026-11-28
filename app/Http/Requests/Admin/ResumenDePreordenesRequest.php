<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

// El periodo del resumen: los mismos tres que ofrece el panel.
class ResumenDePreordenesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['days' => 'sometimes|integer|in:7,30,90'];
    }

    public function dias(): int
    {
        return (int) ($this->validated('days') ?? 30);
    }
}
