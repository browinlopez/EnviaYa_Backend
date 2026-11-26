<?php

declare(strict_types=1);

namespace App\Http\Controllers\Conjunto;

use App\Http\Controllers\Controller;
use App\Queries\ResidentesDelConjunto;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;

class ResidentesController extends Controller
{
    /* --------------------------- RESIDENTES --------------------------- */

    /**
     * Quiénes son, no cuántos: el detalle de una torre.
     *
     * SIN CORREO NI TELÉFONO, y no es un olvido. Una administración lleva
     * legítimamente el registro de quién vive en su edificio —eso es lo que se
     * enseña acá—, pero los datos de CONTACTO de cada vecino son de su
     * relación con la plataforma, no con el conjunto.
     *
     * Lleva cuántos domicilios le entraron por la portería y cuándo fue el
     * último: eso es el libro de entradas del edificio, que es de la
     * administración. Qué pidió, a qué tienda y cuánto pagó, no.
     *
     * Paginado: se pide de a una torre (unos cientos de vecinos como mucho), y
     * el orden por domicilios exige contar antes de cortar la página.
     */
    public function residentesDetalle(Request $request)
    {
        $complexId = (int) $request->attributes->get('complex_id');

        $porPagina = min(100, max(10, (int) $request->query('per_page', 20)));

        /* LAS CONSULTAS VIVEN EN `Queries/ResidentesDelConjunto`, que es lo
           que §12 pide. Aqui queda traducir la peticion y armar la
           respuesta. */
        $lee = new ResidentesDelConjunto;

        $filas = $lee->detalle(
            $complexId,
            torre: $request->query('tower') ?: null,
            buscar: trim((string) $request->query('search')) ?: null,
        );

        /*
         * Los domicilios se cuentan por APARTAMENTO: el libro sabe a qué
         * puerta iba el pedido, no quién de la casa lo pidió.
         */
        [$desde] = $this->ventana($request);
        $puertas = $lee->domiciliosPorPuerta($complexId, $desde);

        $filas = $filas->map(function ($f) use ($puertas, $lee) {
            $p = $puertas[$lee->puerta($f->tower, $f->apartment)] ?? null;
            $f->domicilios = $p['n'] ?? 0;
            $f->ultimo_domicilio = $p['ultimo'] ?? null;

            return $f;
        });

        if ($request->query('orden') === 'domicilios') {
            $filas = $filas->sortByDesc('domicilios')->values();
        }

        $pagina = max(1, (int) $request->query('page', 1));
        $total = $filas->count();

        return response()->json([
            'data' => $filas->forPage($pagina, $porPagina)->values(),
            'meta' => [
                'page' => $pagina,
                'per_page' => $porPagina,
                'total' => $total,
                'last_page' => max(1, (int) ceil($total / $porPagina)),
                'desde' => $desde->toDateString(),
                // Si se llegó al tope, el total no es el del conjunto: se dice.
                'truncado' => $total >= 5000,
            ],
        ]);
    }

    /**
     * Cuántos vecinos usan la plataforma, por torre, y cuántos domicilios les
     * entraron por la portería. Sin nombres: eso es el detalle de cada torre.
     */
    public function residentes(Request $request)
    {
        $complexId = (int) $request->attributes->get('complex_id');

        $lee = new ResidentesDelConjunto;

        $vecinos = $lee->vecinos($complexId);
        $conjunto = $lee->formaDelConjunto($complexId);

        $porTorre = (int) ($conjunto->apartments_per_tower ?? 0);
        $unidades = (int) ($conjunto->towers_count ?? 0) * $porTorre;

        [$desde, $ventana] = $this->ventana($request);
        $puertas = $lee->domiciliosPorPuerta($complexId, $desde);

        $domiciliosTorre = [];
        foreach ($puertas as $clave => $p) {
            $torre = explode('|', $clave)[0];
            $domiciliosTorre[$torre] = ($domiciliosTorre[$torre] ?? 0) + $p['n'];
        }

        $filas = $vecinos->groupBy(fn ($v) => (string) ($v->tower ?? ''))
            ->map(fn ($grupo, $torre) => [
                'tower' => $torre === '' ? null : (string) $torre,
                'residentes' => $grupo->unique('buyer_id')->count(),
                // Apartamentos con al menos un vecino en la plataforma: el
                // «uso» de la torre se mide en hogares, no en personas.
                'unidades_con_uno' => $grupo->filter(fn ($v) => $v->apartment)
                    ->unique(fn ($v) => trim((string) $v->apartment))->count(),
                'unidades' => $torre === '' ? null : ($porTorre ?: null),
                'domicilios' => $torre === '' ? 0 : (int) ($domiciliosTorre[(string) $torre] ?? 0),
            ])
            ->sortBy(fn ($f) => sprintf(
                '%d-%010d-%s',
                $f['tower'] === null ? 1 : 0,
                is_numeric($f['tower']) ? (int) $f['tower'] : 9999999999,
                $f['tower'],
            ))
            ->values();

        $inicioMes = CarbonImmutable::now()->startOfMonth();
        $inicioAntes = $inicioMes->subMonthNoOverflow();
        $nuevosEntre = fn ($desde, $hasta) => $vecinos
            ->filter(fn ($v) => $v->created_at
                && $v->created_at >= $desde->toDateTimeString()
                && (! $hasta || $v->created_at < $hasta->toDateTimeString()))
            ->unique('buyer_id')->count();

        return response()->json([
            'data' => $filas,
            'total' => (int) $vecinos->unique('buyer_id')->count(),
            'resumen' => [
                'unidades' => $unidades ?: null,
                'unidades_con_uno' => (int) $filas->sum('unidades_con_uno'),
                'domicilios' => (int) array_sum($domiciliosTorre),
                'ventana' => $ventana,
                'desde' => $desde->toDateString(),
                'nuevos_mes' => $nuevosEntre($inicioMes, null),
                'nuevos_mes_anterior' => $nuevosEntre($inicioAntes, $inicioMes),
                'mes_anterior' => $inicioAntes->locale('es')->isoFormat('MMMM'),
            ],
        ]);
    }

    /**
     * La ventana de los domicilios: los últimos 7, 30 o 90 días, o este mes.
     * Cualquier otro valor cae en 30 días: nunca se calcula sobre un rango
     * que el cliente invente.
     */
    private function ventana(Request $request): array
    {
        $hoy = CarbonImmutable::now();

        return match ((string) $request->query('ventana', '30')) {
            'mes' => [$hoy->startOfMonth(), 'mes'],
            '7' => [$hoy->subDays(6)->startOfDay(), '7'],
            '90' => [$hoy->subDays(89)->startOfDay(), '90'],
            default => [$hoy->subDays(29)->startOfDay(), '30'],
        };
    }

    /*
     * `puerta()` y `domiciliosPorPuerta()` SE MUDARON a
     * `App\Queries\ResidentesDelConjunto` el 14 de noviembre de 2026, con
     * las tres consultas. §12 pide las consultas complejas en `Queries/`.
     */
}
