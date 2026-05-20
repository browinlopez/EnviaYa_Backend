<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;

class StoreBusinessRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => 'required|string|max:255',
            'phone' => 'nullable|string|max:20',
            'address' => 'nullable|string|max:255',
            'latitude' => 'required|numeric',
            'longitude' => 'required|numeric',
            'municipality_id' => 'required|integer|exists:municipalities,id',
            'identification_number' => 'required|string|max:50',
            'verification_digit' => 'nullable|integer',
            'legal_name' => 'nullable|string|max:255',
            'type_organization_id' => 'required|integer|exists:type_organizations,id',
            'logo' => 'nullable', // can be string or image file
            'category_business_id' => 'required|integer|exists:categories_business,id',
            'state' => 'nullable',
            'products' => 'nullable|array',
            'products.*' => 'integer|exists:products,products_id',
            'domiciliaries' => 'nullable|array',
            'domiciliaries.*' => 'integer|exists:domiciliary,domiciliary_id',
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => 'El nombre del negocio es obligatorio.',
            'name.string' => 'El nombre debe ser una cadena de texto.',
            'name.max' => 'El nombre no puede superar los 255 caracteres.',
            'phone.max' => 'El teléfono no puede superar los 20 caracteres.',
            'address.max' => 'La dirección no puede superar los 255 caracteres.',
            'latitude.required' => 'La latitud es obligatoria.',
            'latitude.numeric' => 'La latitud debe ser un valor numérico.',
            'longitude.required' => 'La longitud es obligatoria.',
            'longitude.numeric' => 'La longitud debe ser un valor numérico.',
            'municipality_id.required' => 'El municipio es obligatorio.',
            'municipality_id.integer' => 'El identificador del municipio debe ser un número entero.',
            'municipality_id.exists' => 'El municipio seleccionado no es válido.',
            'identification_number.required' => 'El número de identificación es obligatorio.',
            'identification_number.max' => 'El número de identificación no puede superar los 50 caracteres.',
            'verification_digit.integer' => 'El dígito de verificación debe ser un número entero.',
            'legal_name.max' => 'El nombre legal no puede superar los 255 caracteres.',
            'type_organization_id.required' => 'El tipo de organización es obligatorio.',
            'type_organization_id.exists' => 'El tipo de organización seleccionado no es válido.',
            'category_business_id.required' => 'La categoría del negocio es obligatoria.',
            'category_business_id.exists' => 'La categoría comercial seleccionada no es válida.',
        ];
    }
}
