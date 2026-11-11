<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Radicar una PQRS desde el panel interno.
 */
class CrearPqrsRequest extends FormRequest
{
    /*
     * El permiso lo resuelve el middleware de la ruta, que es donde vive en
     * este backend. Repetirlo aqui seria una segunda fuente de verdad sobre
     * quien puede que, y la que se olvide de actualizar sera la que mande.
     */
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'type' => 'required|in:peticion,queja,reclamo,sugerencia,felicitacion',
            'channel' => 'sometimes|in:app,whatsapp,llamada,correo,panel',
            'priority' => 'sometimes|in:baja,media,alta',
            'user_id' => 'sometimes|nullable|integer|exists:user,user_id',
            'order_id' => 'sometimes|nullable|integer|exists:orderssales,orderSales_id',
            'business_id' => 'sometimes|nullable|integer|exists:business,busines_id',
            'domiciliary_id' => 'sometimes|nullable|integer|exists:domiciliary,domiciliary_id',
            'contact_name' => 'sometimes|nullable|string|max:150',
            'contact_email' => 'sometimes|nullable|email|max:150',
            'contact_phone' => 'sometimes|nullable|string|max:30',
            'subject' => 'required|string|max:200',
            'description' => 'required|string',
            'assigned_to' => 'sometimes|nullable|integer|exists:user,user_id',
        ];
    }
}
