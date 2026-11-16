<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Crear un negocio desde el panel interno.
 *
 * `NegociosApiController` tenia las reglas en un ayudante privado
 * `reglasNegocio($creando)` que `storeBusiness` y `updateBusiness` se pasaban
 * por parametro —lo que `scripts/validaciones_en_linea.py` llama REGLAS
 * PRESTADAS—. `storeBusiness` se convierte y usa esta clase.
 *
 * `updateBusiness` NO se puede convertir: hace `abort_if(... 404)` antes de
 * validar, y un FormRequest —que valida antes de entrar al metodo— cambiaria
 * ese 404 por un 422 hablando del cuerpo. Asi que pide las reglas aqui, en
 * modo «no creando», y no hay dos copias de las quince reglas.
 *
 * El parametro se sigue llamando `creando` y no `parcial` porque asi se
 * llamaba: renombrarlo mientras se muda el codigo mezcla dos cambios en uno.
 */
class NegocioRequest extends FormRequest
{
    /*
     * El permiso lo resuelve el middleware `modulo:negocios` de la ruta, que
     * es donde vive en este backend.
     */
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return self::reglas(creando: true);
    }

    /**
     * Las reglas del negocio, exigiendo lo obligatorio o no.
     *
     * @return array<string, mixed>
     */
    public static function reglas(bool $creando): array
    {
        $req = $creando ? 'required' : 'sometimes';

        return [
            'name' => "{$req}|string|max:255",
            'phone' => 'sometimes|nullable|string|max:30',
            'address' => 'sometimes|nullable|string|max:255',
            'description' => 'sometimes|nullable|string',
            'razonSocial_DCD' => 'sometimes|nullable|string|max:255',
            'NIT' => 'sometimes|nullable|string|max:50',
            'logo' => 'sometimes|nullable|string',
            // Es el ID de una categoría de negocio: si se aceptara cualquier
            // valor, la tienda quedaría fuera de todos los carruseles y sus
            // productos no podrían clasificarse en ninguna categoría.
            'type' => 'sometimes|nullable|integer|exists:category_business,id',
            'state' => 'sometimes|boolean',
            // El municipio acota la búsqueda de direcciones: sin él, "calle 18
            // carrera 37b" aparece en cualquier ciudad del país.
            'municipality_id' => 'sometimes|nullable|integer|exists:municipalities,id',
            // Coordenadas: el panel las obtiene de un mapa, así que llegan como
            // decimales. Se acotan al rango válido para que un error de captura
            // no deje un punto en mitad del océano.
            'latitude' => 'sometimes|nullable|numeric|between:-90,90',
            'longitude' => 'sometimes|nullable|numeric|between:-180,180',
            /*
             * Tope de crédito: lo que la tienda puede fiar entre todos sus
             * clientes y la deuda que puede arrastrar con la plataforma. Solo
             * lo pone el equipo; el panel del tendero no lo recibe. Vacío: la
             * tienda no vende a crédito.
             */
            'credit_debt_cap' => 'sometimes|nullable|numeric|min:0|max:999999999',
        ];

    }
}
