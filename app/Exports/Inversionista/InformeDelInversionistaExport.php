<?php

declare(strict_types=1);

namespace App\Exports\Inversionista;

use App\Exports\Inversionista\Hojas\HojaCiudades;
use App\Exports\Inversionista\Hojas\HojaIngresos;
use App\Exports\Inversionista\Hojas\HojaMeses;
use App\Exports\Inversionista\Hojas\HojaNegocios;
use App\Exports\Inversionista\Hojas\HojaOperacion;
use App\Exports\Inversionista\Hojas\HojaResumen;
use App\Exports\Inversionista\Hojas\HojaUsuarios;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;
use Maatwebsite\Excel\Concerns\WithProperties;
use Maatwebsite\Excel\Events\BeforeWriting;

/**
 * El informe del inversionista, en Excel.
 *
 * Recibe los datos YA CALCULADOS por `InformeDelInversionista`, que se los
 * pide a los mismos servicios de las pantallas: el archivo dice lo mismo que
 * el panel. Siete hojas, ninguna con nombres de personas ni de negocios:
 *
 *   Resumen     las cifras clave y el reparto por tipo
 *   Mes a mes   doce meses con las series de todos los módulos
 *   Negocios    activos, con ventas y días hasta la primera venta
 *   Ciudades    el avance por ciudad
 *   Usuarios    registrados, activos, recompra y frecuencia
 *   Operación   tiempos, puntualidad, cancelación y flota
 *   Ingresos    ventas, ingreso de la plataforma y comisión efectiva
 */
class InformeDelInversionistaExport implements WithEvents, WithMultipleSheets, WithProperties
{
    public function __construct(private array $datos) {}

    public function sheets(): array
    {
        return [
            new HojaResumen($this->datos),
            new HojaMeses($this->datos),
            new HojaNegocios($this->datos),
            new HojaCiudades($this->datos),
            new HojaUsuarios($this->datos),
            new HojaOperacion($this->datos),
            // La hoja de la plata, solo con el interruptor encendido
            // (`config/inversionista.php`).
            ...(($this->datos['ver_ingresos'] ?? false) ? [new HojaIngresos($this->datos)] : []),
        ];
    }

    public function properties(): array
    {
        $p = $this->datos['periodo'];

        return [
            'creator' => "VeciPa'Ya",
            'title' => 'Informe para inversionistas',
            'subject' => $p['desde'].' a '.$p['hasta'],
            'company' => "VeciPa'Ya",
        ];
    }

    public function registerEvents(): array
    {
        return [
            // Que abra en el Resumen, que es lo que se vino a ver.
            BeforeWriting::class => fn (BeforeWriting $e) => $e->writer->getDelegate()->setActiveSheetIndex(0),
        ];
    }
}
