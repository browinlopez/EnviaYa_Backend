<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Models\Marketing\Coupon;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Crear un cupon de descuento.
 *
 * EL METODO ESTATICO NO ES UN ADORNO. `CuponesApiController` tenia un ayudante
 * privado `validarCupon($request, $parcial)` llamado DOS veces: desde
 * `storeCoupon` con las reglas estrictas y desde `updateCoupon` con `sometimes`,
 * para que una correccion parcial no exija reenviar todo.
 *
 * `storeCoupon` se convierte y usa esta clase. **`updateCoupon` no se puede
 * convertir**: hace una busqueda que contesta 404 ANTES de validar, asi que un
 * FormRequest —que valida antes de entrar al metodo— cambiaria ese 404 por un
 * 422 hablando del cuerpo. Es un cambio de contrato, no una mejora.
 *
 * Asi que las reglas viven aqui, en UN sitio, y `updateCoupon` las pide en modo
 * parcial. La alternativa era dejarlas en el ayudante y tener dos copias: la
 * que valida el FormRequest y la que valida el ayudante. Dos copias de una
 * regla es tenerla mal en una de las dos.
 *
 * Los ocho `validar*` de la API de administracion tenian esta misma forma:
 * store convertible y update con la busqueda delante. Lo destapo comprobar
 * los LLAMANTES uno por uno, porque `scripts/validaciones_en_linea.py`
 * clasificaba el AYUDANTE y no a quien lo llama, y decia «se puede convertir»
 * de un ayudante del que solo la mitad se podia.
 */
class CuponRequest extends FormRequest
{
    /*
     * El permiso lo resuelve el middleware `modulo:` de la ruta, que es donde
     * vive en este backend.
     */
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return self::reglas();
    }

    /**
     * Las reglas, estrictas o parciales.
     *
     * @return array<string, mixed>
     */
    public static function reglas(bool $parcial = false, ?int $ignorar = null): array
    {
        $regla = $parcial ? 'sometimes' : 'required';

        $unico = 'unique:coupons,code'.($ignorar ? ",{$ignorar}" : '');

        return [
            'code' => "{$regla}|string|max:40|{$unico}",
            'description' => 'sometimes|nullable|string|max:255',
            'type' => 'sometimes|in:'.implode(',', Coupon::TIPOS),
            'value' => "{$regla}|numeric|min:0",
            'max_discount' => 'sometimes|nullable|numeric|min:0',
            'min_order' => 'sometimes|numeric|min:0',
            'max_uses' => 'sometimes|nullable|integer|min:1',
            'max_uses_per_user' => 'sometimes|nullable|integer|min:1',
            'business_id' => 'sometimes|nullable|integer|exists:business,busines_id',
            'category_id' => 'sometimes|nullable|integer|exists:category,category_id',
            'advertiser_id' => 'sometimes|nullable|integer|exists:advertisers,id',
            'starts_at' => "{$regla}|date",
            'ends_at' => "{$regla}|date|after_or_equal:starts_at",
            'state' => 'sometimes|boolean',
        ];
    }
}
