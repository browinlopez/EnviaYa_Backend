<?php

declare(strict_types=1);

namespace App\Services\Inversionista;

use Carbon\Carbon;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * USUARIOS: CUÁNTOS SE REGISTRAN, CUÁNTOS COMPRAN Y CUÁNTOS VUELVEN.
 *
 *  · Registrados = cuentas de comprador (rol 1) al cierre del periodo.
 *  · Activos = compradores DISTINTOS con al menos un pedido entregado y
 *    pagado en el periodo (o en el mes, en la serie).
 *  · Recompra = de los activos, qué porcentaje hizo DOS O MÁS pedidos en el
 *    periodo. Es la señal de que la app se volvió costumbre.
 *  · Pedidos por comprador activo = pedidos ÷ activos.
 *  · En conjuntos = compradores vinculados a un conjunto activo
 *    (`buyer_complex`), y qué parte de los registrados son.
 *
 * LOS FILTROS: la ciudad acota a los registrados por su dirección y a los
 * activos por la ciudad del negocio al que le compraron; el tipo de negocio
 * solo acota a los activos (un comprador no es de un oficio, sus compras sí).
 */
class UsuariosDelInversionista extends BaseDelInversionista
{
    public function datos(array $v, array $f): array
    {
        return [
            'cifras' => $this->cifras($v, $f),
            'frecuencia' => $this->frecuencia($v, $f),
            'meses' => $this->meses($v['hasta'], $f),
        ];
    }

    /* ------------------------------ CIFRAS ------------------------------ */

    private function cifras(array $v, array $f): array
    {
        $registrados = $this->compradoresAl($f, $v['hasta'])->count();
        $registradosAntes = $this->compradoresAl($f, $v['hasta_antes'])->count();

        $nuevos = $this->compradores($f)->whereBetween('u.created_at', [$v['desde'], $v['hasta']])->count();
        $nuevosAntes = $this->compradores($f)->whereBetween('u.created_at', [$v['desde_antes'], $v['hasta_antes']])->count();

        $ahora = $this->compras($f, $v['desde'], $v['hasta']);
        $antes = $this->compras($f, $v['desde_antes'], $v['hasta_antes']);

        $enConjuntos = $this->enConjuntos($f);

        return [
            'registrados' => $this->cifra($registrados, $registradosAntes),
            'nuevos' => $this->cifra($nuevos, $nuevosAntes),
            'activos' => $this->cifra($ahora['activos'], $antes['activos']),
            'porcentaje_activos' => $this->porcentaje($ahora['activos'], $registrados),
            'recompra' => $this->cifra(
                $this->porcentaje($ahora['repiten'], $ahora['activos']),
                $this->porcentaje($antes['repiten'], $antes['activos']),
            ) + ['repiten' => $ahora['repiten']],
            'pedidos_por_comprador' => $this->cifra(
                $this->cociente($ahora['pedidos'], $ahora['activos']),
                $this->cociente($antes['pedidos'], $antes['activos']),
            ),
            'en_conjuntos' => [
                'valor' => $enConjuntos,
                'porcentaje' => $this->porcentaje($enConjuntos, $registrados),
            ],
        ];
    }

    /**
     * Los pedidos de cada comprador en un rango, ya agrupados. El
     * identificador del comprador vive solo DENTRO de la subconsulta: de acá
     * sale un renglón de conteos.
     */
    private function porComprador(array $f, Carbon $desde, Carbon $hasta): Builder
    {
        return $this->entregados($f, $desde, $hasta)
            ->groupBy('o.buyer_id')
            ->selectRaw('o.buyer_id, COUNT(*) as n');
    }

    /** @return array{activos:int, repiten:int, pedidos:int} */
    private function compras(array $f, Carbon $desde, Carbon $hasta): array
    {
        $fila = DB::query()->fromSub($this->porComprador($f, $desde, $hasta), 't')
            ->selectRaw('COUNT(*) as activos, COALESCE(SUM(CASE WHEN t.n >= 2 THEN 1 ELSE 0 END), 0) as repiten, COALESCE(SUM(t.n), 0) as pedidos')
            ->first();

        return [
            'activos' => (int) ($fila->activos ?? 0),
            'repiten' => (int) ($fila->repiten ?? 0),
            'pedidos' => (int) ($fila->pedidos ?? 0),
        ];
    }

    /** Compradores vinculados a un conjunto activo (de la ciudad, si se filtra). */
    private function enConjuntos(array $f): int
    {
        return (int) DB::table('buyer_complex as bc')
            ->join('residential_complexes as c', 'c.complex_id', '=', 'bc.complex_id')
            ->join('buyer as bu', 'bu.buyer_id', '=', 'bc.buyer_id')
            ->join('user as u', 'u.user_id', '=', 'bu.user_id')
            ->where('u.rol', self::ROL_COMPRADOR)
            ->where('c.state', 1)
            ->when($f['municipality_id'], fn ($q, $m) => $q->where('c.municipality_id', $m))
            ->distinct()
            ->count('bc.buyer_id');
    }

    /* ---------------------------- FRECUENCIA ---------------------------- */

    /**
     * Cuántos compradores activos hicieron 1, de 2 a 3, de 4 a 9 o 10 y más
     * pedidos en el periodo.
     */
    private function frecuencia(array $v, array $f): array
    {
        $fila = DB::query()->fromSub($this->porComprador($f, $v['desde'], $v['hasta']), 't')
            ->selectRaw('
                COALESCE(SUM(CASE WHEN t.n = 1 THEN 1 ELSE 0 END), 0) as uno,
                COALESCE(SUM(CASE WHEN t.n BETWEEN 2 AND 3 THEN 1 ELSE 0 END), 0) as pocos,
                COALESCE(SUM(CASE WHEN t.n BETWEEN 4 AND 9 THEN 1 ELSE 0 END), 0) as varios,
                COALESCE(SUM(CASE WHEN t.n >= 10 THEN 1 ELSE 0 END), 0) as muchos,
                COUNT(*) as total')
            ->first();

        $total = (int) ($fila->total ?? 0);
        $tramo = fn (string $clave, string $rotulo, $n) => [
            'clave' => $clave,
            'rotulo' => $rotulo,
            'compradores' => (int) $n,
            'porcentaje' => $total ? round((int) $n / $total * 100, 1) : 0,
        ];

        return [
            $tramo('uno', '1 pedido', $fila->uno ?? 0),
            $tramo('pocos', '2 a 3 pedidos', $fila->pocos ?? 0),
            $tramo('varios', '4 a 9 pedidos', $fila->varios ?? 0),
            $tramo('muchos', '10 o más', $fila->muchos ?? 0),
        ];
    }

    /* ------------------------------ MESES ------------------------------- */

    private function meses(Carbon $hasta, array $f): array
    {
        [$inicio, $fin, $meses] = $this->mesesHasta($hasta);
        $mes = $this->mesDe('o.sale_date');

        $porMes = $this->entregados($f, $inicio, $fin)
            ->groupBy('mes', 'o.buyer_id')
            ->selectRaw("{$mes} as mes, o.buyer_id, COUNT(*) as n");

        $activos = DB::query()->fromSub($porMes, 't')
            ->groupBy('t.mes')
            ->selectRaw('t.mes, COUNT(*) as activos, SUM(CASE WHEN t.n >= 2 THEN 1 ELSE 0 END) as repiten, SUM(t.n) as pedidos')
            ->get()
            ->keyBy('mes');

        $nuevos = $this->compradores($f)
            ->whereBetween('u.created_at', [$inicio, $fin])
            ->selectRaw("{$this->mesDe('u.created_at')} as mes, COUNT(*) as n")
            ->groupBy('mes')
            ->pluck('n', 'mes');

        // Los registrados al cierre de cada mes: los de antes de la serie (y
        // los sin fecha) más los nuevos de cada mes, acumulados.
        $acumulado = $this->compradores($f)
            ->where(fn ($w) => $w->whereNull('u.created_at')->orWhere('u.created_at', '<', $inicio))
            ->count();

        return array_map(function ($m) use ($activos, $nuevos, &$acumulado) {
            $a = $activos[$m['mes']] ?? null;
            $n = (int) ($nuevos[$m['mes']] ?? 0);
            $acumulado += $n;
            $act = (int) ($a->activos ?? 0);

            return $this->rotuloDeMes($m) + [
                'registrados' => $acumulado,
                'nuevos' => $n,
                'activos' => $act,
                'recompra' => $this->porcentaje((int) ($a->repiten ?? 0), $act),
                'pedidos_por_comprador' => $this->cociente((int) ($a->pedidos ?? 0), $act),
            ];
        }, $meses);
    }
}
