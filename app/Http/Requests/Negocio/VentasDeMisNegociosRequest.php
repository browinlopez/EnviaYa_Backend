<?php

declare(strict_types=1);

namespace App\Http\Requests\Negocio;

use App\Http\Requests\Concerns\ConRangoDeFechas;
use Illuminate\Foundation\Http\FormRequest;

/**
 * El periodo de «Tus ventas por negocio»: entre dos fechas del calendario.
 * Sin fechas, el mes en curso.
 */
class VentasDeMisNegociosRequest extends FormRequest
{
    use ConRangoDeFechas;

    /** Un año y un día, como el resto de reportes. */
    public const DIAS_MAXIMOS = 366;

    /*
     * El permiso ya lo resolvio el middleware `negocio`: solo un tendero con
     * al menos un negocio llega aca, y la lista de SUS negocios la escribio
     * el en la peticion. Repetirlo seria una segunda fuente de verdad.
     */
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return $this->reglasDelRango();
    }

    protected function diasMaximosDelRango(): int
    {
        return self::DIAS_MAXIMOS;
    }
}
