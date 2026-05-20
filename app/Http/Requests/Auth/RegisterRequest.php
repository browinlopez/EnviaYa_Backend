<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

class RegisterRequest extends FormRequest
{
    use \App\Traits\ValidateVerificationDigit;

    public function authorize(): bool
    {
        return true;
    }

    public function withValidator($validator)
    {
        $validator->after(function ($validator) {
            $typeOrg = $this->input('type_organization_id');
            $nit = $this->input('identification_number');
            $dv = $this->input('verification_digit');

            if ($typeOrg == 1) {
                if (is_null($dv) || $dv === '') {
                    $validator->errors()->add('verification_digit', 'El dígito de verificación es obligatorio para personas jurídicas.');
                    return;
                }

                $correctDv = $this->ValidateVerificationDigit($nit);
                if ($correctDv !== false && $correctDv != $dv) {
                    $validator->errors()->add('verification_digit', "El dígito de verificación es incorrecto. El correcto debe ser: {$correctDv}.");
                }
            }
        });
    }

    public function rules(): array
    {
        return [
            'name'                             => 'required|string|max:255',
            'email'                            => 'required|string|email|unique:users,email',
            'password'                         => 'required|string|min:6',
            'phone'                            => 'nullable|string|max:20',
            'belongs_to_complex'               => 'boolean',
            'complex_id'                       => 'nullable|integer|exists:residential_complexes,complex_id',
            'type_organization_id'             => 'required|integer|exists:type_organizations,id',
            'type_document_identification_id'   => 'nullable|integer|exists:type_document_identifications,id',
            'identification_number'            => 'nullable|string|max:50',
            'verification_digit'               => 'nullable|integer',
            'municipality_id'                  => 'nullable|integer|exists:municipalities,id',
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => 'El nombre es obligatorio.',
            'name.string' => 'El nombre debe ser una cadena de texto.',
            'name.max' => 'El nombre no debe superar los 255 caracteres.',
            'email.required' => 'El correo electrónico es obligatorio.',
            'email.string' => 'El correo electrónico debe ser una cadena de texto.',
            'email.email' => 'El correo electrónico debe tener un formato válido.',
            'email.unique' => 'Este correo electrónico ya se encuentra registrado.',
            'password.required' => 'La contraseña es obligatoria.',
            'password.string' => 'La contraseña debe ser una cadena de texto.',
            'password.min' => 'La contraseña debe tener al menos 6 caracteres.',
            'phone.string' => 'El teléfono debe ser una cadena de texto.',
            'phone.max' => 'El teléfono no debe superar los 20 caracteres.',
            'belongs_to_complex.boolean' => 'El campo de pertenencia a conjunto debe ser verdadero o falso.',
            'complex_id.integer' => 'El ID del conjunto residencial debe ser un número entero.',
            'complex_id.exists' => 'El conjunto residencial seleccionado no es válido.',
            'type_organization_id.required' => 'El tipo de organización es obligatorio.',
            'type_organization_id.integer' => 'El ID del tipo de organización debe ser un número entero.',
            'type_organization_id.exists' => 'El tipo de organización seleccionado no es válido.',
            'type_document_identification_id.integer' => 'El ID del tipo de documento debe ser un número entero.',
            'type_document_identification_id.exists' => 'El tipo de documento seleccionado no es válido.',
            'identification_number.string' => 'El número de identificación debe ser una cadena de texto.',
            'identification_number.max' => 'El número de identificación no debe superar los 50 caracteres.',
            'verification_digit.integer' => 'El dígito de verificación debe ser un número entero.',
            'municipality_id.integer' => 'El ID del municipio debe ser un número entero.',
            'municipality_id.exists' => 'El municipio seleccionado no es válido.',
        ];
    }
}
