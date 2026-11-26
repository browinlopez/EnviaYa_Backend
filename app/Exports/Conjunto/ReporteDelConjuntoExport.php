<?php

declare(strict_types=1);

namespace App\Exports\Conjunto;

use App\Exports\Conjunto\Hojas\HojaCeladores;
use App\Exports\Conjunto\Hojas\HojaConsolidado;
use App\Exports\Conjunto\Hojas\HojaDomiciliarios;
use App\Exports\Conjunto\Hojas\HojaEntradasPorDia;
use App\Exports\Conjunto\Hojas\HojaIdentificacion;
use App\Exports\Conjunto\Hojas\HojaPorTorre;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;
use Maatwebsite\Excel\Concerns\WithProperties;
use Maatwebsite\Excel\Events\BeforeWriting;

/**
 * El reporte del conjunto, en Excel.
 *
 * Recibe los datos YA CALCULADOS por `PulsoDelConjunto::reporte()` en vez de
 * volver a consultarlos, y lee de ellos lo mismo que pinta la pantalla de
 * Reportes (`libro`). Es lo que garantiza que el archivo diga exactamente lo
 * mismo que la pantalla desde la que se descargó: con dos consultas separadas
 * basta un `where` distinto para que el Excel traiga otra cosa parecida, y
 * eso no se nota hasta que alguien compara los dos en una reunión.
 *
 * Seis hojas, ninguna con nombres de residentes: el reporte dice cuántos
 * pedidos llegaron a la torre 4, no quién pidió qué.
 *
 *   Consolidado       la que se imprime: cifras, resúmenes y tres gráficas
 *   Entradas por día  un renglón por día del periodo, con sus pedidos
 *   Identificación    QR, cédula y anotado a mano
 *   Por torre         pedidos por torre de entrega
 *   Domiciliarios     los que más entran
 *   Celadores         qué registró cada uno (la tarjeta «Por celador»)
 */
class ReporteDelConjuntoExport implements WithEvents, WithMultipleSheets, WithProperties
{
    private CifrasDelReporte $cifras;

    public function __construct(array $datos, string $conjunto)
    {
        $this->cifras = new CifrasDelReporte($datos, $conjunto);
    }

    public function sheets(): array
    {
        return [
            new HojaConsolidado($this->cifras),
            new HojaEntradasPorDia($this->cifras),
            new HojaIdentificacion($this->cifras),
            new HojaPorTorre($this->cifras),
            new HojaDomiciliarios($this->cifras),
            new HojaCeladores($this->cifras),
        ];
    }

    public function properties(): array
    {
        return [
            'creator' => "VeciPa'Ya",
            'title' => 'Reporte del conjunto · '.$this->cifras->conjunto,
            'subject' => $this->cifras->periodo(),
            'company' => "VeciPa'Ya",
        ];
    }

    public function registerEvents(): array
    {
        return [
            // Que abra en el Consolidado, que es lo que se vino a ver.
            BeforeWriting::class => fn (BeforeWriting $e) => $e->writer->getDelegate()->setActiveSheetIndex(0),
        ];
    }
}
