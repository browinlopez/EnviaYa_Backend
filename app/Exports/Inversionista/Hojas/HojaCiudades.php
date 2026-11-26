<?php

declare(strict_types=1);

namespace App\Exports\Inversionista\Hojas;

/** El avance por ciudad. Por ciudad, nunca por negocio. */
class HojaCiudades extends HojaDelInforme
{
    public function title(): string
    {
        return 'Ciudades';
    }

    protected function encabezado(): string
    {
        return 'Expansión por ciudad';
    }

    protected function columnas(): array
    {
        return ['A' => 22, 'B' => 16, 'C' => 11, 'D' => 11, 'E' => 12, 'F' => 11, 'G' => 16, 'H' => 13, 'I' => 14, 'J' => 14];
    }

    protected function cuerpo(): void
    {
        $filas = array_map(fn ($c) => [
            $c['ciudad'], $c['departamento'], $c['negocios'], $c['conjuntos'], $c['residentes'],
            $c['pedidos'], $c['ventas'], $c['compradores'], $c['compradores_por_negocio'], $c['radio_km'] ?? null,
        ], $this->d['expansion']['por_ciudad']);

        $this->seccion('Avance por ciudad');
        $this->tabla(
            ['Ciudad', 'Departamento', 'Negocios', 'Conjuntos', 'Vecinos en conjuntos', 'Pedidos', 'Ventas', 'Compradores', 'Compradores por negocio', 'Entregas hasta (km)'],
            $filas ?: [['Sin presencia todavía']],
            [2 => self::ENTERO, 3 => self::ENTERO, 4 => self::ENTERO, 5 => self::ENTERO, 6 => self::PESOS, 7 => self::ENTERO, 8 => self::DECIMAL, 9 => self::DECIMAL],
        );
        $this->fila();
        $this->nota('La ciudad de un negocio es la que se guarda al crearlo; los que no la tienen van en «Sin ciudad». Compradores = cuentas con una dirección activa en la ciudad. Pedidos y ventas, del periodo. Entregas hasta = nueve de cada diez domicilios llegan a menos de esa distancia en línea recta desde el negocio (con 5 entregas medidas o más).');
    }
}
