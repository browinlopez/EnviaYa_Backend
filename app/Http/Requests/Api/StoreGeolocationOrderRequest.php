<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;

class StoreGeolocationOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'domiciliary_id' => 'required|exists:domiciliary,domiciliary_id',
            'order_sales_id' => 'required|exists:orders_sales,id',
            'latitude' => 'required|numeric',
            'longitude' => 'required|numeric',
            'state' => 'nullable|integer',
        ];
    }
}
