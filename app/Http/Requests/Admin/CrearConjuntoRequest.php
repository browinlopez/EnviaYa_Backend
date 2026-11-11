<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Dar de alta un conjunto, con su ficha completa.
 *
 * La ficha vive aqui y no solo en el panel de aliados porque el conjunto NACE
 * en este formulario: sin estos campos quedaba dado de alta sin foto, sin
 * telefono y sin a quien llamar.
 */
class CrearConjuntoRequest extends FormRequest
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
            'name' => 'required|string|max:255',
            'address' => 'nullable|string|max:255',
            'people_count' => 'nullable|integer|min:0',
            'state' => 'nullable|boolean',
            // Mismo tratamiento que los negocios: el conjunto se ubica en el
            // mapa y su municipio acota la búsqueda de direcciones.
            'latitude' => 'nullable|numeric|between:-90,90',
            'longitude' => 'nullable|numeric|between:-180,180',
            'municipality_id' => 'nullable|integer|exists:municipalities,id',
            /*
             * Cuántas torres y cuántos apartamentos tiene cada una.
             *
             * Se asume que todas son iguales. Es una simplificación consciente:
             * sirve para dimensionar el conjunto y para ofrecer listas en el
             * registro, no para validar que una dirección exista.
             */
            'towers_count' => 'nullable|integer|min:1|max:500',
            'apartments_per_tower' => 'nullable|integer|min:1|max:2000',

            /*
             * LA FICHA DEL CONJUNTO.
             *
             * El backend ya guardaba estos campos y el panel de aliados ya los
             * editaba, pero el formulario del ADMIN —que es donde el conjunto
             * NACE— no los mandaba: quedaba dado de alta sin foto, sin
             * telefono y sin a quien llamar, y alguien tenia que entrar por el
             * otro panel a completarlo.
             *
             * La foto va como URL porque MediaService ya sube a Cloudflare y
             * devuelve una: el formulario sube primero y manda el enlace.
             */
            'photo' => 'nullable|string|max:2048',
            'phone' => 'nullable|string|max:40',
            'email' => 'nullable|email|max:120',
            'admin_name' => 'nullable|string|max:120',
            'nit' => 'nullable|string|max:40',
            'gate_notes' => 'nullable|string',
            /*
             * Que la porteria tenga que pedirle permiso al residente antes de
             * dejar entrar. Nace apagado: encenderlo cambia como trabaja el
             * celador y esa es una decision del conjunto, no un valor por
             * defecto.
             */
            'require_authorization' => 'nullable|boolean',
        ];
    }
}
