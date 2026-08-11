<?php

namespace App\Http\Requests;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ProfileUpdateRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => [
                'required',
                'string',
                'lowercase',
                'email',
                'max:255',
                // La PK de user es user_id (no id); con ->ignore(null) la regla
                // unique chocaba contra el propio registro al no cambiar el email.
                Rule::unique(User::class)->ignore($this->user()->user_id, 'user_id'),
            ],
        ];
    }
}
