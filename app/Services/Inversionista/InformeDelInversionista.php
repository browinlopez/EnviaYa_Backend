<?php

declare(strict_types=1);

namespace App\Services\Inversionista;

use Carbon\Carbon;
use Dompdf\Dompdf;
use Dompdf\Options;

/**
 * EL INFORME DEL INVERSIONISTA: LOS SEIS MÓDULOS EN UN ARCHIVO.
 *
 * No vuelve a consultar nada por su cuenta: le pide a cada módulo sus datos
 * con la MISMA ventana y los MISMOS filtros, de modo que el Excel y el PDF
 * dicen exactamente lo que dicen las pantallas. Con consultas aparte basta un
 * `where` distinto para que el archivo traiga otra cifra parecida, y eso no se
 * nota hasta que alguien compara los dos en una junta.
 */
class InformeDelInversionista
{
    public function __construct(
        private ResumenDelInversionista $resumen,
        private NegociosDelInversionista $negocios,
        private ExpansionDelInversionista $expansion,
        private UsuariosDelInversionista $usuarios,
        private OperacionDelInversionista $operacion,
    ) {}

    public function datos(array $pedido): array
    {
        [$v, $f] = $this->resumen->contexto($pedido);
        $opciones = $this->resumen->opciones();

        $ciudad = collect($opciones['ciudades'])->firstWhere('id', $f['municipality_id']);
        $tipo = collect($opciones['tipos'])->firstWhere('id', $f['tipo']);

        return [
            'periodo' => $this->resumen->periodo($v),
            'filtros' => $f,
            'alcance' => [
                'ciudad' => $ciudad['nombre'] ?? null,
                'tipo' => $tipo['nombre'] ?? null,
            ],
            'generado' => Carbon::now()->toIso8601String(),
            'resumen' => $this->resumen->datos($v, $f),
            'negocios' => $this->negocios->datos($v, $f),
            'expansion' => $this->expansion->datos($v, $f),
            'usuarios' => $this->usuarios->datos($v, $f),
            'operacion' => $this->operacion->datos($v, $f),
        ];
    }

    /** «informe-vecipaya-2026-11-01-a-2026-11-30». */
    public function nombre(array $datos, string $extension): string
    {
        return 'informe-vecipaya-'.$datos['periodo']['desde'].'-a-'.$datos['periodo']['hasta'].'.'.$extension;
    }

    /** El mismo informe en PDF, en carta y sin nada externo. */
    public function pdf(array $datos): string
    {
        $html = view('documentos.informe-inversionista', ['d' => $datos])->render();

        $opciones = new Options;
        $opciones->set('defaultFont', 'DejaVu Sans');
        // Nada externo: el documento tiene que salir igual sin red.
        $opciones->set('isRemoteEnabled', false);
        $opciones->set('isHtml5ParserEnabled', true);

        $dompdf = new Dompdf($opciones);
        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->setPaper('letter', 'portrait');
        $dompdf->render();

        /*
         * «Página X de Y» en el pie de cada hoja. Va por el lienzo y no por
         * CSS: dompdf no sabe cuántas páginas hay mientras arma el HTML
         * (`counter(pages)` sale en cero), y acá ya lo sabe.
         */
        $lienzo = $dompdf->getCanvas();
        $letra = $dompdf->getFontMetrics()->getFont('DejaVu Sans');
        $lienzo->page_text(
            $lienzo->get_width() - 92,
            $lienzo->get_height() - 30,
            'Página {PAGE_NUM} de {PAGE_COUNT}',
            $letra,
            7,
            [0.54, 0.53, 0.64],
        );

        return $dompdf->output();
    }
}
