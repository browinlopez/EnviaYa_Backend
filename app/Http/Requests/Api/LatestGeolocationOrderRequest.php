<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;

class LatestGeolocationOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'domiciliary_id' => 'required|exists:domiciliary,domiciliary_id'
        ];
    }
}
