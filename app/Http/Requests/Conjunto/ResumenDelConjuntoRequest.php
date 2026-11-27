<?php

declare(strict_types=1);

namespace App\Http\Requests\Conjunto;

use App\Http\Requests\Concerns\ConRangoDeFechas;
use Illuminate\Foundation\Http\FormRequest;

/**
 * El periodo del tablero del conjunto: los últimos `dias` (lo de siempre;
 * la portería pide `dias=1`) o entre dos fechas del calendario.
 */
class ResumenDelConjuntoRequest extends FormRequest
{
    use ConRangoDeFechas;

    /*
     * Tres meses y un poco: lo que cabe en la gráfica de un punto por día
     * sin volverse una línea ilegible. Un trimestre del calendario (agosto a
     * octubre) son 92 días.
     */
    public const DIAS_MAXIMOS = 92;

    /*
     * El permiso lo resuelve el middleware de la ruta, que es donde vive en
     * este backend. Repetirlo aqui seria una segunda fuente de verdad.
     */
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // Se acota en el controlador (1 a 90), como siempre.
            'dias' => 'nullable|integer',
        ] + $this->reglasDelRango();
    }

    protected function diasMaximosDelRango(): int
    {
        return self::DIAS_MAXIMOS;
    }
}
