<?php

declare(strict_types=1);

namespace App\Http\Requests\Concerns;

use Carbon\CarbonImmutable;
use Illuminate\Validation\Validator;

/**
 * UN RANGO DE FECHAS ELEGIDO EN EL CALENDARIO: `desde` y `hasta`, los dos o
 * ninguno.
 *
 * El panel usa UN solo calendario en todas las pantallas (atajos y días
 * sueltos o rangos). Los tableros que antes solo sabían de ventanas fijas
 * —`dias=30`, `ventana=mes`— aprenden aquí a recibir un rango cualquiera, y
 * siguen aceptando lo de antes: la app móvil manda esos parámetros.
 *
 * Una fecha que no es fecha, un `hasta` antes del `desde` o un rango más
 * largo que el tope de la pantalla son un 422 que dice qué campo. Recortar
 * en silencio haría que quien pidió un año leyera tres meses creyendo que es
 * un año.
 */
trait ConRangoDeFechas
{
    /** Cuántos días puede abarcar el rango, contando los dos extremos. */
    abstract protected function diasMaximosDelRango(): int;

    /** @return array<string, string> */
    protected function reglasDelRango(): array
    {
        return [
            'desde' => 'nullable|date_format:Y-m-d|required_with:hasta',
            'hasta' => 'nullable|date_format:Y-m-d|required_with:desde|after_or_equal:desde',
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $v) {
                if ($v->errors()->hasAny(['desde', 'hasta']) || ! $this->filled('desde') || ! $this->filled('hasta')) {
                    return;
                }

                $tope = $this->diasMaximosDelRango();

                if ($this->diasDelRango() > $tope) {
                    $v->errors()->add('hasta', "El periodo puede tener hasta {$tope} días.");
                }
            },
        ];
    }

    /**
     * El rango pedido, del primer instante del `desde` al último del `hasta`;
     * null si no vino (entonces manda el parámetro de siempre).
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}|null
     */
    public function rango(): ?array
    {
        if (! $this->filled('desde') || ! $this->filled('hasta')) {
            return null;
        }

        return [
            CarbonImmutable::parse((string) $this->input('desde'))->startOfDay(),
            CarbonImmutable::parse((string) $this->input('hasta'))->endOfDay(),
        ];
    }

    /** Cuántos días tiene el rango, contando los dos extremos. */
    private function diasDelRango(): int
    {
        $desde = CarbonImmutable::parse((string) $this->input('desde'))->startOfDay();
        $hasta = CarbonImmutable::parse((string) $this->input('hasta'))->startOfDay();

        return (int) abs($desde->diffInDays($hasta)) + 1;
    }
}
