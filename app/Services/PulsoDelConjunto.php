<?php

namespace App\Services;

use App\Models\Conjunto\ComplexStaff;
use App\Support\SqlPortable;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Qué está pasando en un conjunto.
 *
 * Un solo sitio para las cifras que enseñan el panel de aliados y sus
 * reportes. Están juntas porque son las MISMAS: si el resumen las calculara
 * por su cuenta y el reporte por la suya, el día que no cuadren nadie sabría
 * cuál de las dos está mal.
 *
 * DE DÓNDE SALE «UN PEDIDO DEL CONJUNTO»
 *
 * De la DIRECCIÓN DE ENTREGA, no del conjunto que declaró el comprador al
 * registrarse:
 *
 *     orderssales → user_address (address_id) → complex_id
 *
 * Alguien puede vivir en un conjunto y pedir a la oficina, o al revés. Lo que
 * cuenta como movimiento del edificio es a dónde llegó el paquete, que es
 * además lo mismo que mira la portería para dejar entrar.
 *
 * Todas las consultas se acotan por `complex_id`, que viene de la sesión y
 * nunca de la petición.
 */
class PulsoDelConjunto
{
    /** Los pedidos entregados EN este conjunto, con el pago confirmado. */
    private function pedidos(int $complexId)
    {
        return DB::table('orderssales as o')
            ->join('user_address as ua', 'ua.address_id', '=', 'o.address_id')
            ->where('ua.complex_id', $complexId)
            // Un pedido cuyo pago en línea sigue sin confirmarse no debe
            // existir para nadie, tampoco para contarlo.
            ->whereNotIn('o.payment_state', ['pending_online', 'rejected']);
    }

    private function entradas(int $complexId)
    {
        return DB::table('complex_entries as e')->where('e.complex_id', $complexId);
    }

    /**
     * El tablero de inicio.
     *
     * `$dias` es la ventana de las series. Catorce por defecto: dos semanas
     * completas, que es lo que deja comparar un martes con el martes anterior
     * sin que la gráfica se vuelva ilegible.
     */
    public function resumen(int $complexId, int $dias = 14): array
    {
        $hoy   = CarbonImmutable::today();
        $desde = $hoy->subDays($dias - 1);

        /*
         * Todo lo de abajo mira la MISMA ventana que eligió quien mira: si dice
         * «Hoy», la hora pico, las torres y la identificación son de hoy. Antes
         * cada gráfica tenía su propia ventana fija y el tablero mezclaba
         * «hoy» con «los últimos 30 días» sin decirlo.
         */
        return [
            'hoy'          => $this->deHoy($complexId, $hoy),
            'comparativa'  => $this->comparativa($complexId, $hoy),
            'series'       => $this->series($complexId, $desde, $hoy),
            'por_hora'     => $this->porHora($complexId, $desde),
            'torres'       => $this->torres($complexId, $desde),
            'identificacion' => $this->identificacion($complexId, $desde),
            'comunidad'    => $this->comunidad($complexId),
            'ultimas'      => $this->ultimasEntradas($complexId, 6),
        ];
    }

    private function deHoy(int $complexId, CarbonImmutable $hoy): array
    {
        $entradasHoy = (clone $this->entradas($complexId))
            ->whereDate('e.created_at', $hoy->toDateString());

        return [
            'pedidos'  => (int) (clone $this->pedidos($complexId))
                ->whereDate('o.sale_date', $hoy->toDateString())->count(),
            'entradas' => (int) (clone $entradasHoy)->count(),
            // Cuántas personas distintas, no cuántas veces. Un domiciliario que
            // entra tres veces es una sola persona en la portería.
            'domiciliarios' => (int) (clone $entradasHoy)
                ->where('e.kind', 'domiciliario')
                ->distinct()->count('e.domiciliary_id'),
            /*
             * Todo lo que no es domiciliario de la plataforma: visitas,
             * servicio, domicilios de otras apps. Se cuenta aparte porque son
             * dos flujos distintos —uno lo verifica el sistema y el otro lo
             * anota el celador— y sumarlos escondería justo eso.
             */
            'externos' => (int) (clone $entradasHoy)
                ->where('e.kind', '!=', 'domiciliario')->count(),
            // Entraron hoy y no han salido. Es la cifra que convierte el libro
            // en control de acceso.
            'adentro' => (int) (clone $entradasHoy)->whereNull('e.exited_at')->count(),
            // El mismo número partido: «2 domiciliarios · 3 visitas» dice a
            // quién hay que ir a buscar; «5» solo, no.
            'adentro_domiciliarios' => (int) (clone $entradasHoy)->whereNull('e.exited_at')
                ->where('e.kind', 'domiciliario')->count(),
            /*
             * Contra el mismo día de la semana pasada y hasta la misma hora.
             * Un viernes contra un jueves no dice nada, y un día a medias
             * contra uno entero siempre parece una caída.
             */
            'semana_pasada' => (int) (clone $this->entradas($complexId))
                ->whereBetween('e.created_at', [$hoy->subWeek()->startOfDay(), now()->subWeek()])
                ->count(),
            'dia_semana_pasada' => $hoy->subWeek()->locale('es')->isoFormat('dddd'),
            'en_camino' => (int) (clone $this->pedidos($complexId))
                ->where('o.state', 3)->count(),
        ];
    }

    /** Esta semana contra la anterior, para saber si sube o baja. */
    private function comparativa(int $complexId, CarbonImmutable $hoy): array
    {
        $inicio   = $hoy->subDays(6);
        $anterior = $hoy->subDays(13);

        $cuenta = fn ($q, $col, $d, $h) => (int) (clone $q)
            ->whereBetween($col, [$d->startOfDay(), $h->endOfDay()])->count();

        $pedidos  = $this->pedidos($complexId);
        $entradas = $this->entradas($complexId);

        return [
            'pedidos' => [
                'actual'   => $cuenta($pedidos, 'o.sale_date', $inicio, $hoy),
                'anterior' => $cuenta($pedidos, 'o.sale_date', $anterior, $inicio->subDay()),
            ],
            'entradas' => [
                'actual'   => $cuenta($entradas, 'e.created_at', $inicio, $hoy),
                'anterior' => $cuenta($entradas, 'e.created_at', $anterior, $inicio->subDay()),
            ],
        ];
    }

    /**
     * Serie diaria de pedidos y entradas.
     *
     * Se rellenan los días sin movimiento con cero. Sin eso la gráfica une el
     * lunes con el jueves en una línea recta y un fin de semana muerto parece
     * actividad constante.
     */
    private function series(int $complexId, CarbonImmutable $desde, CarbonImmutable $hasta): array
    {
        $porDia = fn ($q, $col) => (clone $q)
            ->whereBetween($col, [$desde->startOfDay(), $hasta->endOfDay()])
            ->selectRaw(SqlPortable::soloFecha($col) . ' as dia, COUNT(*) as total')
            ->groupBy('dia')
            ->pluck('total', 'dia');

        $pedidos  = $porDia($this->pedidos($complexId), 'o.sale_date');
        $entradas = $porDia($this->entradas($complexId), 'e.created_at');

        $filas = [];

        for ($d = $desde; $d->lessThanOrEqualTo($hasta); $d = $d->addDay()) {
            $clave = $d->toDateString();

            $filas[] = [
                'dia'      => $clave,
                'etiqueta' => $d->locale('es')->isoFormat('DD MMM'),
                'pedidos'  => (int) ($pedidos[$clave] ?? 0),
                'entradas' => (int) ($entradas[$clave] ?? 0),
            ];
        }

        return $filas;
    }

    /**
     * A qué horas entra la gente a la portería.
     *
     * Es la cifra que de verdad sirve para organizar turnos, y no se puede
     * sacar de ninguna otra pantalla. Las 24 horas siempre, aunque estén en
     * cero: una gráfica que sólo pinta las horas con movimiento miente sobre
     * la forma del día.
     */
    private function porHora(int $complexId, CarbonImmutable $desde): array
    {
        $cuentas = $this->entradas($complexId)
            ->where('e.created_at', '>=', $desde->startOfDay())
            ->selectRaw(SqlPortable::hora('e.created_at') . ' as hora, COUNT(*) as total')
            ->groupBy('hora')
            ->pluck('total', 'hora');

        return collect(range(0, 23))->map(fn ($h) => [
            'hora'     => $h,
            'etiqueta' => str_pad((string) $h, 2, '0', STR_PAD_LEFT) . ':00',
            'total'    => (int) ($cuentas[$h] ?? 0),
        ])->all();
    }

    /**
     * A qué torre iba la gente que entró.
     *
     * Sale del libro de la portería y no de los pedidos: una visita o el
     * técnico del gas también suben a una torre, y es la portería —no la
     * tienda— la que sabe a dónde fue cada uno. Ordenadas por número para que
     * la torre 3 esté siempre en el mismo sitio y se compare a ojo.
     */
    private function torres(int $complexId, CarbonImmutable $desde): Collection
    {
        return $this->entradas($complexId)
            ->where('e.created_at', '>=', $desde->startOfDay())
            ->whereNotNull('e.tower')
            ->where('e.tower', '!=', '')
            ->selectRaw('e.tower as torre, COUNT(*) as entradas')
            ->groupBy('e.tower')
            ->get()
            ->sortBy(fn ($f) => [is_numeric($f->torre) ? (int) $f->torre : PHP_INT_MAX, $f->torre])
            ->values()
            ->map(fn ($f) => ['torre' => (string) $f->torre, 'entradas' => (int) $f->entradas]);
    }

    /**
     * Con qué se identificaron los que entraron.
     *
     * No es un dato de curiosidad: el código lo genera la app del domiciliario
     * y caduca en cinco minutos; la cédula sólo prueba que alguien dijo un
     * número. Una portería donde casi todo entra por cédula está controlando
     * menos de lo que cree.
     */
    private function identificacion(int $complexId, CarbonImmutable $desde): array
    {
        $filas = $this->entradas($complexId)
            ->where('e.created_at', '>=', $desde->startOfDay())
            /*
             * Sólo domiciliarios de la plataforma. Un visitante no tiene
             * código que generar ni cédula que verificar contra nada: lo anota
             * el celador. Meterlos en esta proporción la haría caer sin que
             * nadie hubiera dejado de pedir el código.
             */
            ->where('e.kind', 'domiciliario')
            ->selectRaw('e.method, COUNT(*) as total')
            ->groupBy('e.method')
            ->pluck('total', 'method');

        $codigo = (int) ($filas['codigo'] ?? 0);
        $cedula = (int) ($filas['cedula'] ?? 0);
        $total  = $codigo + $cedula;

        /*
         * Lo anotado a mano —visitas, servicio, domicilios de otras apps— va
         * aparte y NO entra en `total` ni en `parte_codigo`, por lo dicho
         * arriba. Se da para la barra de «cómo entró la gente» completa, que
         * es otra pregunta: cuánto de la portería verifica el sistema.
         */
        $anotadas = (int) $this->entradas($complexId)
            ->where('e.created_at', '>=', $desde->startOfDay())
            ->where(fn ($q) => $q->where('e.kind', '!=', 'domiciliario')->orWhere('e.method', 'manual'))
            ->count();

        return [
            'codigo' => $codigo,
            'cedula' => $cedula,
            'total'  => $total,
            'parte_codigo' => $total ? round($codigo / $total, 4) : null,
            'manual' => $anotadas,
            'todas'  => $total + $anotadas,
        ];
    }

    private function comunidad(int $complexId): array
    {
        $conjunto = DB::table('residential_complexes')
            ->where('complex_id', $complexId)
            ->first(['towers_count', 'apartments_per_tower']);

        $unidades = ($conjunto->towers_count ?? 0) * ($conjunto->apartments_per_tower ?? 0);

        $residentes = (int) DB::table('buyer_complex')
            ->where('complex_id', $complexId)->count();

        return [
            'residentes' => $residentes,
            'unidades'   => $unidades ?: null,
            // Sobre unidades y no sobre personas: una cuenta suele ser un
            // hogar, no un individuo.
            'penetracion' => $unidades ? round($residentes / $unidades, 4) : null,
            'celadores'  => (int) ComplexStaff::where('complex_id', $complexId)
                ->where('role', ComplexStaff::CELADOR)
                ->where('state', true)
                ->count(),
        ];
    }

    public function ultimasEntradas(int $complexId, int $cuantas = 6): Collection
    {
        return $this->entradas($complexId)
            ->leftJoin('domiciliary as d', 'd.domiciliary_id', '=', 'e.domiciliary_id')
            ->leftJoin('user as u', 'u.user_id', '=', 'd.user_id')
            ->orderByDesc('e.created_at')
            ->limit($cuantas)
            ->get([
                'e.id', 'e.kind', 'e.method', 'e.orders_count', 'e.orders', 'e.created_at', 'e.exited_at',
                'e.tower', 'e.apartment', 'e.visitor_name', 'e.visitor_company',
                'u.name as domiciliary_name', 'd.document',
            ])
            ->map(function ($e) {
                $e->destino = self::destinoDe($e);
                unset($e->orders);

                return $e;
            });
    }

    /**
     * A dónde iba una entrada, como se dice por citófono: «T3-502 · T7-101».
     *
     * El de un domiciliario son las puertas de SUS pedidos —puede traer dos—;
     * el de los demás, la torre y el apartamento que anotó el celador. El
     * detalle de los pedidos no sale de acá: quien mira el libro sólo
     * necesita a dónde iba.
     */
    public static function destinoDe(object $e): ?string
    {
        $orders  = $e->orders ?? null;
        $pedidos = is_string($orders) ? json_decode($orders, true) : (array) $orders;

        $puertas = collect($pedidos ?: [])
            ->map(fn ($p) => ($p['tower'] ?? null)
                ? 'T' . $p['tower'] . (($p['apartment'] ?? null) ? '-' . $p['apartment'] : '')
                : null)
            ->filter()->unique()->values();

        if ($puertas->isEmpty() && ($e->tower ?? null)) {
            $puertas = collect(['T' . $e->tower . (($e->apartment ?? null) ? '-' . $e->apartment : '')]);
        }

        return $puertas->implode(' · ') ?: null;
    }

    /**
     * Lo que este domiciliario ha hecho EN ESTE conjunto.
     *
     * Va en la ficha de la portería. Un celador que ve «primera vez que entra»
     * mira con más cuidado que uno que ve «lleva 40 entradas»; sin el dato,
     * las dos situaciones se ven exactamente igual.
     */
    public function historialEnElConjunto(int $complexId, int $domiciliaryId): array
    {
        $q = $this->entradas($complexId)->where('e.domiciliary_id', $domiciliaryId);

        $ultima = (clone $q)->orderByDesc('e.created_at')->value('e.created_at');

        return [
            'entradas' => (int) (clone $q)->count(),
            'ultima'   => $ultima,
            'pedidos_entregados' => (int) $this->pedidos($complexId)
                ->where('o.domiciliary_id', $domiciliaryId)
                ->where('o.state', 4)
                ->count(),
        ];
    }

    /* --------------------------- REPORTES --------------------------- */

    /**
     * El reporte del administrador, en una ventana de fechas.
     *
     * Separado del resumen aunque comparta consultas: el resumen responde «qué
     * está pasando ahora» y siempre mira los últimos días; esto responde «qué
     * pasó en marzo» y la ventana la elige quien pregunta.
     */
    public function reporte(int $complexId, CarbonImmutable $desde, CarbonImmutable $hasta): array
    {
        $ventana = fn ($q, $col) => (clone $q)
            ->whereBetween($col, [$desde->startOfDay(), $hasta->endOfDay()]);

        $pedidos  = fn () => $ventana($this->pedidos($complexId), 'o.sale_date');
        $entradas = fn () => $ventana($this->entradas($complexId), 'e.created_at');

        $dias = max(1, $desde->diffInDays($hasta) + 1);

        return [
            'periodo' => [
                'desde' => $desde->toDateString(),
                'hasta' => $hasta->toDateString(),
                'dias'  => $dias,
            ],
            'totales' => [
                'pedidos'  => (int) $pedidos()->count(),
                'entregados' => (int) $pedidos()->where('o.state', 4)->count(),
                'cancelados' => (int) $pedidos()->where('o.state', 5)->count(),
                'entradas' => (int) $entradas()->count(),
                'domiciliarios' => (int) $entradas()->distinct()->count('e.domiciliary_id'),
                // Cuánto se movió el edificio, no cuánto ganó nadie: acá el
                // conjunto no cobra ni recibe. Es una medida de actividad.
                'valor_pedidos' => (float) $pedidos()->sum('o.total'),
                'promedio_diario' => round($pedidos()->count() / $dias, 2),
            ],
            'por_torre' => $ventana($this->pedidos($complexId), 'o.sale_date')
                ->whereNotNull('ua.tower')
                ->selectRaw('ua.tower as torre, COUNT(*) as pedidos, SUM(o.total) as valor')
                ->groupBy('ua.tower')
                ->orderByRaw('CAST(ua.tower AS UNSIGNED), ua.tower')
                ->get(),
            'por_dia' => $ventana($this->pedidos($complexId), 'o.sale_date')
                ->selectRaw(SqlPortable::soloFecha('o.sale_date') . ' as dia, COUNT(*) as pedidos')
                ->groupBy('dia')
                ->orderBy('dia')
                ->get(),
            'domiciliarios' => $ventana($this->entradas($complexId), 'e.created_at')
                ->leftJoin('domiciliary as d', 'd.domiciliary_id', '=', 'e.domiciliary_id')
                ->leftJoin('user as u', 'u.user_id', '=', 'd.user_id')
                ->selectRaw('u.name as nombre, d.document, COUNT(*) as entradas, SUM(e.orders_count) as pedidos')
                ->groupBy('u.name', 'd.document')
                ->orderByDesc('entradas')
                ->get(),
            'identificacion' => [
                'codigo' => (int) (clone $entradas())->where('e.method', 'codigo')->count(),
                'cedula' => (int) (clone $entradas())->where('e.method', 'cedula')->count(),
            ],
            'libro' => $this->libro($complexId, $desde, $hasta),
        ];
    }

    /**
     * El periodo visto desde el libro de la portería.
     *
     * Lo de arriba (`totales`, `por_torre`…) sale de los pedidos y lo sigue
     * usando el Excel tal cual. Esto sale de las ENTRADAS: es lo que la
     * portería vio pasar —incluidas visitas y otras apps— y es lo que pinta la
     * pantalla. Va todo junto para que la pantalla no arme cifras sumando en
     * el navegador.
     */
    private function libro(int $complexId, CarbonImmutable $desde, CarbonImmutable $hasta): array
    {
        $dias = max(1, $desde->diffInDays($hasta) + 1);

        $filas = $this->entradas($complexId)
            ->whereBetween('e.created_at', [$desde->startOfDay(), $hasta->endOfDay()])
            ->leftJoin('domiciliary as d', 'd.domiciliary_id', '=', 'e.domiciliary_id')
            ->leftJoin('user as u', 'u.user_id', '=', 'd.user_id')
            ->get(['e.kind', 'e.method', 'e.orders', 'e.orders_count', 'e.created_at', 'e.exited_at',
                'e.domiciliary_id', 'u.name']);

        // Igual de largo, justo antes: «+12 % frente al periodo anterior».
        $anterior = (int) $this->entradas($complexId)
            ->whereBetween('e.created_at', [
                $desde->subDays($dias)->startOfDay(),
                $desde->subDay()->endOfDay(),
            ])->count();

        $porDia = $filas->groupBy(fn ($e) => substr((string) $e->created_at, 0, 10));
        $serie = [];
        for ($d = $desde; $d->lessThanOrEqualTo($hasta); $d = $d->addDay()) {
            $serie[] = [
                'dia'      => $d->toDateString(),
                'etiqueta' => $d->locale('es')->isoFormat('D MMM'),
                'entradas' => isset($porDia[$d->toDateString()]) ? $porDia[$d->toDateString()]->count() : 0,
            ];
        }

        $domis = $filas->where('kind', 'domiciliario');

        $torres = [];
        foreach ($domis as $e) {
            $pedidos = is_string($e->orders) ? json_decode($e->orders, true) : [];
            foreach ($pedidos ?: [] as $p) {
                if (!empty($p['tower'])) {
                    $torres[(string) $p['tower']] = ($torres[(string) $p['tower']] ?? 0) + 1;
                }
            }
        }
        arsort($torres);

        $minutos = fn ($e) => $e->exited_at
            ? max(0, (int) round((strtotime((string) $e->exited_at) - strtotime((string) $e->created_at)) / 60))
            : null;

        $quienes = $domis->groupBy('domiciliary_id')
            ->map(function ($g) use ($minutos) {
                $tiempos = $g->map($minutos)->filter(fn ($m) => $m !== null);

                return [
                    'nombre'   => $g->first()->name ?? 'Sin nombre',
                    'entradas' => $g->count(),
                    'por_qr'   => round($g->where('method', 'codigo')->count() / max(1, $g->count()), 4),
                    'minutos'  => $tiempos->isEmpty() ? null : (int) round($tiempos->avg()),
                ];
            })
            ->sortByDesc('entradas')
            ->take(8)
            ->values();

        return [
            'entradas'          => $filas->count(),
            'entradas_anterior' => $anterior,
            'pedidos_recibidos' => (int) $domis->sum('orders_count'),
            'visitantes'        => $filas->where('kind', '!=', 'domiciliario')->count(),
            'identificacion'    => [
                'codigo' => $domis->where('method', 'codigo')->count(),
                'cedula' => $domis->where('method', 'cedula')->count(),
                'manual' => $filas->count() - $domis->where('method', 'codigo')->count() - $domis->where('method', 'cedula')->count(),
            ],
            'por_dia'       => $serie,
            'por_torre'     => collect($torres)->map(fn ($n, $t) => ['torre' => (string) $t, 'pedidos' => $n])->values(),
            'domiciliarios' => $quienes,
        ];
    }
}
