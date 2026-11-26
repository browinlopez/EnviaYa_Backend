<?php

declare(strict_types=1);

namespace App\Http\Requests\Negocio;

use App\Models\Product\ProductBusiness;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

/**
 * La foto de un producto que solo vende este negocio.
 *
 * EL ORDEN DE LAS RESPUESTAS SE CONSERVA. Antes el controlador buscaba
 * primero y validaba después: un producto ajeno respondía 404 y uno que
 * venden otros negocios, 422, sin mirar el archivo. Un FormRequest valida
 * antes de entrar al método, así que esas dos comprobaciones viven aquí, en
 * `authorize()`, que corre ANTES que `rules()`, y responden exactamente lo
 * mismo que respondían. El negocio sale de la sesión (middleware `negocio`),
 * nunca del cuerpo.
 */
class SubirFotoDeProductoRequest extends FormRequest
{
    /** @var array{0: string, 1: int}|null */
    private ?array $rechazo = null;

    public function authorize(): bool
    {
        $id = (int) $this->route('id');
        $businessId = (int) $this->attributes->get('busines_id');

        $propio = ProductBusiness::where('products_id', $id)
            ->where('busines_id', $businessId)
            ->exists();

        if (! $propio) {
            $this->rechazo = ['Ese producto no es de tu negocio.', 404];

            return false;
        }

        $otras = ProductBusiness::where('products_id', $id)
            ->where('busines_id', '!=', $businessId)
            ->count();

        if ($otras > 0) {
            $this->rechazo = [
                'Otros negocios venden este mismo producto: su foto es de todos y no se cambia desde acá.',
                422,
            ];

            return false;
        }

        return true;
    }

    protected function failedAuthorization(): void
    {
        [$mensaje, $estado] = $this->rechazo ?? ['No autorizado.', 403];

        throw new HttpResponseException(response()->json(['message' => $mensaje], $estado));
    }

    public function rules(): array
    {
        return [
            'file' => 'required|file|image|max:8192',
        ];
    }
}
