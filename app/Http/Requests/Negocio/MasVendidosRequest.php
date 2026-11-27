<?php

declare(strict_types=1);

namespace App\Http\Requests\Negocio;

use App\Http\Requests\Concerns\ConRangoDeFechas;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Que se vende mas: en una ventana de dias (`dias`, lo de siempre, lo que
 * manda la app) o entre dos fechas del calendario (`desde` y `hasta`).
 */
class MasVendidosRequest extends FormRequest
{
    use ConRangoDeFechas;

    /** Un año y un día, como el resto de reportes. */
    public const DIAS_MAXIMOS = 366;

    /*
     * El permiso ya lo resolvio el middleware `negocio`: puso en la peticion
     * el negocio de la sesion y piso lo que mandara el cliente. Repetirlo aca
     * seria una segunda fuente de verdad sobre quien puede que.
     */
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'dias' => 'sometimes|integer|in:7,30,90,365',
            'limite' => 'sometimes|integer|min:1|max:50',
        ] + $this->reglasDelRango();
    }

    protected function diasMaximosDelRango(): int
    {
        return self::DIAS_MAXIMOS;
    }
}
