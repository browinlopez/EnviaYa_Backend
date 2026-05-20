<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;

class MakePaymentPaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'order_id' => 'required|integer|exists:orders_sales,id',
            'payment_method' => 'nullable|array',
            'device_fingerprint' => 'nullable|array',
            'payment_gateway' => 'required|string|exists:payment_gateways,name',
        ];
    }

    public function messages(): array
    {
        return [
            'payment_gateway.required' => 'La pasarela de pago es obligatoria.',
            'payment_gateway.exists' => 'La pasarela de pago seleccionada no es válida.',
        ];
    }
}
