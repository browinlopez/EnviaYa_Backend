<?php

declare(strict_types=1);

namespace App\Http\Requests\Conjunto;

use App\Http\Requests\Concerns\ConRangoDeFechas;
use Illuminate\Foundation\Http\FormRequest;

/**
 * En qué periodo se cuentan los domicilios de los residentes: una ventana
 * con nombre (`ventana=7|30|90|mes|mes_pasado`, lo de siempre) o entre dos
 * fechas del calendario.
 *
 * `ventana` NO se valida contra la lista: cualquier otro valor cae en 30
 * días, como hasta ahora. Rechazarlo con un 422 sería romperle la pantalla a
 * una app que mande algo viejo.
 */
class ResidentesDelConjuntoRequest extends FormRequest
{
    use ConRangoDeFechas;

    /** Un año y un día, como los reportes del conjunto. */
    public const DIAS_MAXIMOS = 366;

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
            'ventana' => 'nullable|string|max:20',
        ] + $this->reglasDelRango();
    }

    protected function diasMaximosDelRango(): int
    {
        return self::DIAS_MAXIMOS;
    }
}
