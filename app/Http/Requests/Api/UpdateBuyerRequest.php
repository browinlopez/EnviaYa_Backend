<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;

class UpdateBuyerRequest extends FormRequest
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

            if ($typeOrg == 2) {
                if (is_null($dv) || $dv === '') {
                    $validator->errors()->add('verification_digit', 'El dígito de verificación es obligatorio para personas jurídicas.');
                    return;
                }

                $correctDv = $this->ValidateVerificationDigit($nit);
                if ($correctDv !== false && $correctDv != $dv) {
                    $validator->errors()->add('verification_digit', "El dígito de verificación es incorrecto. Debería ser {$correctDv}.");
                }
            }
        });
    }

    public function rules(): array
    {
        return [
            'qualification' => 'nullable|numeric',
            'state' => 'required|boolean',
            'belongs_to_complex' => 'required|boolean',
            'complex_id' => 'nullable|exists:residential_complexes,complex_id',
            'type_document_identification_id' => 'nullable|exists:document_types,id',
            'identification_number' => 'nullable|string|max:50',
            'verification_digit' => 'nullable|string|max:1',
            'municipality_id' => 'nullable|exists:municipalities,id',
            'type_organization_id' => 'required|exists:type_organizations,id',
        ];
    }
}
