<?php

declare(strict_types=1);

namespace App\Http\Controllers\Conjunto;

use App\Exports\Conjunto\ReporteDelConjuntoExport;
use App\Http\Controllers\Controller;
use App\Http\Requests\Conjunto\ResumenDelConjuntoRequest;
use App\Http\Requests\Conjunto\VentanaDelConjuntoRequest;
use App\Services\PulsoDelConjunto;
use Carbon\CarbonImmutable;
use Maatwebsite\Excel\Facades\Excel;

/**
 * El tablero del conjunto y sus reportes.
 *
 * El conjunto sale de la sesión en las tres acciones. No hay ni un parámetro
 * que diga de qué edificio son las cifras, que es justo como se acaban
 * enseñando las de otro.
 */
class ResumenDelConjuntoController extends Controller
{
    public function __construct(private PulsoDelConjunto $pulso) {}

    /**
     * Lo que está pasando ahora. Lo ven el dueño y el celador.
     *
     * El periodo de las gráficas: entre dos fechas del calendario del panel
     * (`desde` y `hasta`), o los últimos `dias` como siempre —la portería
     * pide `dias=1`—.
     */
    public function resumen(ResumenDelConjuntoRequest $request)
    {
        $complexId = (int) $request->attributes->get('complex_id');

        $rango = $request->rango();

        if ($rango) {
            $desde = $rango[0]->startOfDay();
            $hasta = $rango[1]->startOfDay();
        } else {
            $dias = (int) ($request->validated()['dias'] ?? 14);
            // Acotado: con 365 la gráfica no se lee y la consulta agrupa un año
            // entero para pintar una línea de un píxel por día.
            // Desde 1: «Hoy» es la vista que más se mira en una portería.
            $dias = max(1, min($dias, 90));
            $hasta = CarbonImmutable::today();
            $desde = $hasta->subDays($dias - 1);
        }

        return response()->json(
            $this->pulso->resumen($complexId, $desde, $hasta) + [
                'dias' => (int) $desde->diffInDays($hasta) + 1,
                'desde' => $desde->toDateString(),
                'hasta' => $hasta->toDateString(),
            ],
        );
    }

    /**
     * El reporte del periodo. Sólo el administrador.
     *
     * Lleva nombre de residente en ninguna parte: el reporte dice cuántos
     * pedidos llegaron a la torre 4, no quién pidió qué. Son datos personales
     * de terceros y la relación de cada vecino es con la plataforma.
     */
    public function reporte(VentanaDelConjuntoRequest $request)
    {
        $complexId = (int) $request->attributes->get('complex_id');

        [$desde, $hasta] = $this->ventana($request);

        return response()->json(
            $this->pulso->reporte($complexId, $desde, $hasta),
        );
    }

    public function excel(VentanaDelConjuntoRequest $request)
    {
        $complexId = (int) $request->attributes->get('complex_id');

        [$desde, $hasta] = $this->ventana($request);

        $conjunto = $request->attributes->get('complex_name')
            ?? \DB::table('residential_complexes')->where('complex_id', $complexId)->value('name');

        $datos = $this->pulso->reporte($complexId, $desde, $hasta);

        $nombre = 'reporte-conjunto-'
            .$desde->toDateString().'-a-'.$hasta->toDateString().'.xlsx';

        return Excel::download(
            new ReporteDelConjuntoExport($datos, (string) $conjunto),
            $nombre,
        );
    }

    /**
     * La ventana de fechas.
     *
     * Por defecto los últimos 30 días. Se acota a un año: más que eso agrupa
     * decenas de miles de filas para una pantalla que nadie lee entera, y la
     * petición se cae por tiempo en vez de decir que el rango es demasiado
     * grande.
     */
    private function ventana(VentanaDelConjuntoRequest $request): array
    {
        $hoy = CarbonImmutable::today();

        $hasta = $request->filled('hasta')
            ? CarbonImmutable::parse($request->query('hasta'))
            : $hoy;

        $desde = $request->filled('desde')
            ? CarbonImmutable::parse($request->query('desde'))
            : $hasta->subDays(29);

        // Invertidas se corrigen en vez de devolver cero resultados: es un
        // error de dedo y cero filas se lee como "no hubo movimiento".
        if ($desde->greaterThan($hasta)) {
            [$desde, $hasta] = [$hasta, $desde];
        }

        if ($desde->diffInDays($hasta) > 366) {
            $desde = $hasta->subDays(366);
        }

        return [$desde, $hasta];
    }
}
