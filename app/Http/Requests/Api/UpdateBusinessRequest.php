<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;

class UpdateBusinessRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'busines_id' => 'sometimes|required|integer|exists:business,busines_id',
            'name' => 'nullable|string|max:255',
            'phone' => 'nullable|string|max:20',
            'address' => 'nullable|string|max:255',
            'municipality_id' => 'nullable|integer|exists:municipalities,id',
            'identification_number' => 'nullable|string|max:50',
            'verification_digit' => 'nullable|integer',
            'legal_name' => 'nullable|string|max:255',
            'type_organization_id' => 'nullable|integer|exists:type_organizations,id',
            'logo' => 'nullable', // string or file
            'category_business_id' => 'nullable|integer|exists:categories_business,id',
            'state' => 'nullable',
            'owner_ids' => 'nullable|array',
            'owner_ids.*' => 'integer|exists:owner,owner_id',
            'products' => 'nullable|array',
            'products.*' => 'integer|exists:products,products_id',
            'domiciliaries' => 'nullable|array',
            'domiciliaries.*' => 'integer|exists:domiciliary,domiciliary_id',
        ];
    }

    public function messages(): array
    {
        return [
            'busines_id.exists' => 'El negocio seleccionado no es válido.',
            'name.string' => 'El nombre debe ser una cadena de texto.',
            'name.max' => 'El nombre no puede superar los 255 caracteres.',
            'phone.max' => 'El teléfono no puede superar los 20 caracteres.',
            'address.max' => 'La dirección no puede superar los 255 caracteres.',
            'municipality_id.exists' => 'El municipio seleccionado no es válido.',
            'identification_number.max' => 'El número de identificación no puede superar los 50 caracteres.',
            'verification_digit.integer' => 'El dígito de verificación debe ser un número entero.',
            'legal_name.max' => 'El nombre legal no puede superar los 255 caracteres.',
            'type_organization_id.exists' => 'El tipo de organización seleccionado no es válido.',
            'category_business_id.exists' => 'La categoría comercial seleccionada no es válida.',
        ];
    }
}
