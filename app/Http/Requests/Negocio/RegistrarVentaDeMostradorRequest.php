<?php

declare(strict_types=1);

namespace App\Http\Requests\Negocio;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Una venta en el mostrador: sus líneas y cómo se pagó.
 *
 * Validaba en línea dentro del controlador; el acuerdo del proyecto es que
 * cada endpoint nuevo estrene su FormRequest (ver
 * `scripts/validaciones_en_linea.py`). Las reglas son las mismas.
 */
class RegistrarVentaDeMostradorRequest extends FormRequest
{
    /** La puerta la cuida la ruta (sesión y pertenencia al negocio). */
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'lineas' => 'required|array|min:1|max:100',
            'lineas.*.products_id' => 'required|integer',
            'lineas.*.amount' => 'nullable|integer|min:1|max:1000',
            'lineas.*.pedido_por' => 'nullable|in:valor,cantidad',
            'lineas.*.requested_value' => 'nullable|numeric|min:100|max:1000000',
            'lineas.*.requested_qty' => 'nullable|numeric|min:0.001|max:5000',
            /*
             * EL PRECIO QUE SE CAMBIÓ EN EL MOSTRADOR. En persona el dueño
             * rebaja o redondea --«a usted se lo dejo en $3.000»--: es su
             * venta, en su negocio. Sin él, manda el precio del producto.
             */
            'lineas.*.price' => 'nullable|numeric|min:1|max:100000000',
            // `qr`: Bre-B por Bold. Ver `CobroDeMostradorConQr`.
            'forma_de_pago' => 'nullable|in:efectivo,transferencia,qr',
            'cash_tendered' => 'nullable|numeric|min:0|max:10000000',
        ];
    }
}
