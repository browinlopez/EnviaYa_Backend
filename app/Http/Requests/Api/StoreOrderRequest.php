<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;

class StoreOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'payer_id' => 'required|integer|exists:buyers,id',
            'business_id' => 'required|integer|exists:business,id',
            'address_id' => 'required_if:pickup,false|integer|exists:user_address,id',
            'products' => 'required|array|min:1',
            'products.*.product_id' => 'required|integer|exists:products,id',
            'products.*.quantity' => 'required|integer|min:1',
            'products.*.unit_price' => 'required|numeric|min:0',
            'payment_method_id' => 'required|integer|exists:payment_methods,id',
            'payment_form_id' => 'required|integer|exists:payment_forms,id',
            'payment_method' => 'nullable|array',
            'payment_gateway_id' => 'required|integer|exists:payment_gateways,id',
            'pickup' => 'sometimes|boolean',
            'pickup_time' => 'nullable|required_if:pickup,true|date',
        ];
    }

    public function messages(): array
    {
        return [
            'payment_gateway_id.required' => 'La pasarela de pago es obligatoria.',
            'payment_gateway_id.exists' => 'La pasarela de pago seleccionada no es válida.',
        ];
    }
}
