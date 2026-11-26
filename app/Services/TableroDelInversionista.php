<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Order\OrdersSales;
use Carbon\Carbon;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * EL TABLERO DEL INVERSIONISTA: LA EMPRESA EN CIFRAS, SIN UNA SOLA PERSONA.
 *
 * Responde «¿cómo va VeciPa'Ya?» para quien puso la plata y no opera nada:
 * cuántos negocios hay, cuántos compradores, cuántos pedidos, de cuánto es el
 * pedido promedio, cómo crece mes a mes y en qué ciudades está.
 *
 * SOLO AGREGADOS. Ninguna consulta de este servicio selecciona un nombre, un
 * teléfono, un correo ni el identificador de una persona o de un pedido: todo
 * sale de `COUNT`, `SUM` y `MIN` agrupados. Los únicos identificadores que
 * viajan son los de ciudades y tipos de negocio, que son catálogos. Hay una
 * prueba que lo vigila (`tests/Feature/Inversionista`).
 *
 * LAS DEFINICIONES, en un solo sitio:
 *
 *  · Pedido = entregado (`state` 4) y con el pago confirmado (fuera los que
 *    esperan un pago en línea o lo tienen rechazado). Un pedido en curso puede
 *    cancelarse todavía y uno sin pagar no es plata: contarlos inflaría las
 *    cifras que más se van a repetir en una junta.
 *  · Ventas = suma de `total` de esos pedidos. Ticket promedio = ventas entre
 *    pedidos.
 *  · Negocio activo = `business.state` 1, es decir, visible en la app.
 *  · Usuario registrado = cuenta de comprador (rol 1).
 *  · Ciudad de un negocio = `business.municipality_id`, la que se guarda al
 *    crearlo. Los que no la tienen van a la fila «Sin ciudad».
 *
 * EL PERIODO ANTERIOR de los totales (negocios, usuarios) se reconstruye por
 * fecha de alta: lo que existía al cierre de ese periodo. `created_at` existe
 * solo desde agosto de 2026; lo anterior no tiene fecha y se cuenta como que
 * ya estaba, de modo que entra en el total pero nunca en «nuevos».
 */
class TableroDelInversionista
{
    /** Cuenta de comprador en la tabla `rol`. */
    private const ROL_COMPRADOR = 1;

    private const MESES = ['ene', 'feb', 'mar', 'abr', 'may', 'jun', 'jul', 'ago', 'sep', 'oct', 'nov', 'dic'];

    public function __construct(private GeneradorDeReportes $reportes) {}

    /**
     * @param  array{desde?:?string, hasta?:?string, municipality_id?:?int, tipo?:?int}  $pedido
     */
    public function tablero(array $pedido): array
    {
        /*
         * La ventana es la de los reportes del panel interno: tope de 366
         * días, fechas al revés enderezadas y el periodo anterior de igual
         * tamaño. Se le pasa como `from`/`to`, que es lo que lee.
         */
        $v = $this->reportes->ventanaDelReporte(new Request(array_filter([
            'from' => $pedido['desde'] ?? null,
            'to' => $pedido['hasta'] ?? null,
        ])));

        $f = [
            'municipality_id' => $this->entero($pedido['municipality_id'] ?? null),
            'tipo' => $this->entero($pedido['tipo'] ?? null),
        ];

        return [
            'periodo' => [
                'desde' => $v['desde']->toDateString(),
                'hasta' => $v['hasta']->toDateString(),
                'dias' => $v['dias'],
                'desde_anterior' => $v['desde_antes']->toDateString(),
                'hasta_anterior' => $v['hasta_antes']->toDateString(),
            ],
            'filtros' => $f,
            'cifras' => $this->cifras($v, $f),
            'por_tipo' => $this->porTipo($v, $f),
            'meses' => $this->meses($v['hasta'], $f),
            'por_ciudad' => $this->porCiudad($v, $f),
            'opciones' => $this->opciones(),
        ];
    }

    /* ------------------------------ CIFRAS ------------------------------ */

    private function cifras(array $v, array $f): array
    {
        $existiaAl = fn (Builder $q, string $col, Carbon $corte) => $q->where(
            fn ($w) => $w->whereNull($col)->orWhere($col, '<=', $corte)
        );

        $negocios = $existiaAl($this->negocios($f), 'b.created_at', $v['hasta'])->count();
        $negociosAntes = $existiaAl($this->negocios($f), 'b.created_at', $v['hasta_antes'])->count();

        $conPedidos = $this->entregados($f, $v['desde'], $v['hasta'], true)
            ->where('b.state', 1)
            ->distinct()
            ->count('o.busines_id');

        $usuarios = $existiaAl($this->compradores($f), 'u.created_at', $v['hasta'])->count();
        $usuariosAntes = $existiaAl($this->compradores($f), 'u.created_at', $v['hasta_antes'])->count();
        $nuevos = $this->compradores($f)->whereBetween('u.created_at', [$v['desde'], $v['hasta']])->count();
        $nuevosAntes = $this->compradores($f)->whereBetween('u.created_at', [$v['desde_antes'], $v['hasta_antes']])->count();

        [$pedidos, $ventas] = $this->totales($this->entregados($f, $v['desde'], $v['hasta']));
        [$pedidosAntes, $ventasAntes] = $this->totales($this->entregados($f, $v['desde_antes'], $v['hasta_antes']));

        $ticket = $pedidos > 0 ? round($ventas / $pedidos, 2) : null;
        $ticketAntes = $pedidosAntes > 0 ? round($ventasAntes / $pedidosAntes, 2) : null;

        return [
            'negocios_activos' => $this->cifra($negocios, $negociosAntes) + ['con_pedidos' => $conPedidos],
            'usuarios_registrados' => $this->cifra($usuarios, $usuariosAntes) + [
                'nuevos' => $nuevos,
                'nuevos_anterior' => $nuevosAntes,
            ],
            'pedidos' => $this->cifra($pedidos, $pedidosAntes),
            'ventas' => $this->cifra($ventas, $ventasAntes),
            'ticket_promedio' => $this->cifra($ticket, $ticketAntes),
        ];
    }

    /** @return array{0:int, 1:float} pedidos y ventas */
    private function totales(Builder $q): array
    {
        $fila = $q->selectRaw('COUNT(*) as n, COALESCE(SUM(o.total), 0) as ventas')->first();

        return [(int) ($fila->n ?? 0), round((float) ($fila->ventas ?? 0), 2)];
    }

    private function cifra(int|float|null $valor, int|float|null $anterior): array
    {
        return [
            'valor' => $valor,
            'anterior' => $anterior,
            'variacion' => $this->variacion($valor, $anterior),
        ];
    }

    /**
     * Variación porcentual, o null si no hay con qué comparar.
     *
     * Contra un cero no hay porcentaje: «+100 %» o «+∞» sobre un periodo vacío
     * no es crecimiento, es que antes no había datos. La pantalla dice «nuevo».
     */
    private function variacion(int|float|null $actual, int|float|null $anterior): ?float
    {
        if ($actual === null || $anterior === null || (float) $anterior === 0.0) {
            return null;
        }

        return round((($actual - $anterior) / abs($anterior)) * 100, 1);
    }

    /* ----------------------------- POR TIPO ----------------------------- */

    /**
     * Los negocios activos de cada oficio.
     *
     * NO lleva el filtro de tipo, a propósito: es el reparto entre tipos, y
     * filtrado por uno solo quedaría una tarjeta al 100 % y tres en cero. Sí
     * lleva el de ciudad.
     */
    private function porTipo(array $v, array $f): array
    {
        $sinTipo = ['municipality_id' => $f['municipality_id'], 'tipo' => null];

        $negocios = $this->negocios($sinTipo)
            ->selectRaw('b.type as tipo, COUNT(*) as n')
            ->groupBy('b.type')
            ->pluck('n', 'tipo');

        $pedidos = $this->entregados($sinTipo, $v['desde'], $v['hasta'], true)
            ->selectRaw('b.type as tipo, COUNT(*) as n, COALESCE(SUM(o.total), 0) as ventas')
            ->groupBy('b.type')
            ->get()
            ->keyBy('tipo');

        $total = (int) $negocios->sum();

        return DB::table('category_business')
            ->orderBy('id')
            ->get(['id', 'name'])
            ->map(function ($t) use ($negocios, $pedidos, $total) {
                $n = (int) ($negocios[$t->id] ?? 0);
                $p = $pedidos[$t->id] ?? null;

                return [
                    'id' => (int) $t->id,
                    ...$this->oficio((string) $t->name),
                    'negocios' => $n,
                    'porcentaje' => $total > 0 ? round($n / $total * 100, 1) : 0,
                    'pedidos' => (int) ($p->n ?? 0),
                    'ventas' => round((float) ($p->ventas ?? 0), 2),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * Cómo se llama cada oficio en pantalla, y su clave.
     *
     * El catálogo dice «Farmacia» y «Tienda de repuesto»; la app y el panel
     * dicen «Droguería» y «Repuestos». La clave es la de las ilustraciones
     * del panel (`public/marca`).
     *
     * @return array{clave:string, nombre:string}
     */
    private function oficio(string $nombre): array
    {
        $n = Str::lower(Str::ascii($nombre));

        return match (true) {
            str_contains($n, 'farmac'), str_contains($n, 'drog') => ['clave' => 'drogueria', 'nombre' => 'Droguería'],
            str_contains($n, 'repuest') => ['clave' => 'repuestos', 'nombre' => 'Repuestos'],
            str_contains($n, 'restaur') => ['clave' => 'restaurante', 'nombre' => 'Restaurante'],
            str_contains($n, 'tienda') => ['clave' => 'tienda', 'nombre' => 'Tienda'],
            default => ['clave' => 'otro', 'nombre' => $nombre],
        };
    }

    /* ------------------------------ MESES ------------------------------- */

    /**
     * Los doce meses que terminan en el mes de `hasta`, con su variación.
     *
     * Se piden trece para que el primero de los doce también tenga contra
     * qué compararse. No depende del periodo elegido salvo en dónde termina:
     * el crecimiento se lee siempre a un año vista.
     */
    private function meses(Carbon $hasta, array $f): array
    {
        $fin = $hasta->copy()->endOfMonth();
        $inicio = $hasta->copy()->startOfMonth()->subMonths(12);
        $mes = $this->expresionMes();

        $pedidos = $this->entregados($f, $inicio, $fin)
            ->selectRaw("{$mes('o.sale_date')} as mes, COUNT(*) as n, COALESCE(SUM(o.total), 0) as ventas")
            ->groupBy('mes')
            ->get()
            ->keyBy('mes');

        $negocios = $this->negocios($f)
            ->whereBetween('b.created_at', [$inicio, $fin])
            ->selectRaw("{$mes('b.created_at')} as mes, COUNT(*) as n")
            ->groupBy('mes')
            ->pluck('n', 'mes');

        $usuarios = $this->compradores($f)
            ->whereBetween('u.created_at', [$inicio, $fin])
            ->selectRaw("{$mes('u.created_at')} as mes, COUNT(*) as n")
            ->groupBy('mes')
            ->pluck('n', 'mes');

        $hoy = Carbon::today();
        $serie = [];
        $previo = null;

        for ($m = $inicio->copy(); $m->lte($fin); $m->addMonth()) {
            $clave = $m->format('Y-m');
            $fila = [
                'mes' => $clave,
                'etiqueta' => self::MESES[$m->month - 1],
                'anio' => $m->year,
                // El mes en curso todavía no termina: compararlo entero contra
                // uno cerrado siempre lo haría ver como una caída.
                'en_curso' => $m->isSameMonth($hoy),
                'pedidos' => (int) ($pedidos[$clave]->n ?? 0),
                'ventas' => round((float) ($pedidos[$clave]->ventas ?? 0), 2),
                'negocios_nuevos' => (int) ($negocios[$clave] ?? 0),
                'usuarios_nuevos' => (int) ($usuarios[$clave] ?? 0),
            ];

            $fila['variacion'] = [
                'pedidos' => $this->variacion($fila['pedidos'], $previo['pedidos'] ?? null),
                'ventas' => $this->variacion($fila['ventas'], $previo['ventas'] ?? null),
                'negocios_nuevos' => $this->variacion($fila['negocios_nuevos'], $previo['negocios_nuevos'] ?? null),
                'usuarios_nuevos' => $this->variacion($fila['usuarios_nuevos'], $previo['usuarios_nuevos'] ?? null),
            ];

            $serie[] = $fila;
            $previo = $fila;
        }

        // El mes número trece solo servía de base para la primera variación.
        return array_slice($serie, 1);
    }

    /**
     * `AAAA-MM` de una fecha, en el dialecto del motor.
     *
     * `DATE_FORMAT` es de MySQL y las pruebas corren sobre SQLite: sin esto
     * la serie funciona en producción y revienta justo donde se comprueba.
     */
    private function expresionMes(): \Closure
    {
        $mysql = in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true);

        return fn (string $col) => $mysql
            ? "DATE_FORMAT({$col}, '%Y-%m')"
            : "strftime('%Y-%m', {$col})";
    }

    /* ---------------------------- POR CIUDAD ---------------------------- */

    /**
     * El avance por ciudad: negocios (por tipo), conjuntos, pedidos, ventas,
     * compradores y desde cuándo hay presencia.
     *
     * Entra toda ciudad donde haya algo: un negocio, un conjunto, un pedido o
     * un comprador con dirección ahí. Una ciudad con compradores y sin
     * negocios no es ruido: es demanda esperando oferta, que es justo lo que
     * un inversionista quiere ver.
     */
    private function porCiudad(array $v, array $f): array
    {
        $filas = [];
        $fila = function (?int $id) use (&$filas): string {
            $k = $id === null ? 'sin' : (string) $id;
            $filas[$k] ??= [
                'municipality_id' => $id,
                'negocios' => 0,
                'por_tipo' => [],
                'conjuntos' => 0,
                'pedidos' => 0,
                'ventas' => 0.0,
                'compradores' => 0,
                'desde' => null,
            ];

            return $k;
        };

        $tipos = DB::table('category_business')->get(['id', 'name'])
            ->mapWithKeys(fn ($t) => [$t->id => $this->oficio((string) $t->name)]);

        $negocios = $this->negocios($f)
            ->selectRaw('b.municipality_id as m, b.type as tipo, COUNT(*) as n, MIN(b.created_at) as desde')
            ->groupBy('b.municipality_id', 'b.type')
            ->get();

        foreach ($negocios as $n) {
            $k = $fila($n->m === null ? null : (int) $n->m);
            $filas[$k]['negocios'] += (int) $n->n;

            $oficio = $tipos[$n->tipo] ?? ['clave' => 'otro', 'nombre' => 'Otro'];
            $filas[$k]['por_tipo'][$oficio['clave']] ??= $oficio + ['negocios' => 0];
            $filas[$k]['por_tipo'][$oficio['clave']]['negocios'] += (int) $n->n;

            if ($n->desde && ($filas[$k]['desde'] === null || $n->desde < $filas[$k]['desde'])) {
                $filas[$k]['desde'] = $n->desde;
            }
        }

        $conjuntos = DB::table('residential_complexes as c')
            ->where('c.state', 1)
            ->when($f['municipality_id'], fn ($q, $m) => $q->where('c.municipality_id', $m))
            ->selectRaw('c.municipality_id as m, COUNT(*) as n')
            ->groupBy('c.municipality_id')
            ->get();

        foreach ($conjuntos as $c) {
            $filas[$fila($c->m === null ? null : (int) $c->m)]['conjuntos'] += (int) $c->n;
        }

        $pedidos = $this->entregados($f, $v['desde'], $v['hasta'], true)
            ->selectRaw('b.municipality_id as m, COUNT(*) as n, COALESCE(SUM(o.total), 0) as ventas')
            ->groupBy('b.municipality_id')
            ->get();

        foreach ($pedidos as $p) {
            $k = $fila($p->m === null ? null : (int) $p->m);
            $filas[$k]['pedidos'] += (int) $p->n;
            $filas[$k]['ventas'] += (float) $p->ventas;
        }

        $compradores = DB::table('user_address as ua')
            ->join('user as u', 'u.user_id', '=', 'ua.user_id')
            ->where('u.rol', self::ROL_COMPRADOR)
            ->where('ua.state', 1)
            ->whereNotNull('ua.municipality_id')
            ->when($f['municipality_id'], fn ($q, $m) => $q->where('ua.municipality_id', $m))
            ->selectRaw('ua.municipality_id as m, COUNT(DISTINCT ua.user_id) as n')
            ->groupBy('ua.municipality_id')
            ->get();

        foreach ($compradores as $c) {
            $filas[$fila((int) $c->m)]['compradores'] += (int) $c->n;
        }

        /*
         * Los compradores sin ciudad son los que no tienen ninguna dirección
         * con municipio. Solo se cuentan cuando no se filtra por ciudad: con
         * el filtro puesto, la fila «Sin ciudad» no tiene sentido.
         */
        if (! $f['municipality_id']) {
            $sinCiudad = DB::table('user as u')
                ->where('u.rol', self::ROL_COMPRADOR)
                ->whereNotExists(fn ($q) => $q->from('user_address as ua')
                    ->whereColumn('ua.user_id', 'u.user_id')
                    ->where('ua.state', 1)
                    ->whereNotNull('ua.municipality_id')
                    ->selectRaw('1'))
                ->count();

            if ($sinCiudad > 0) {
                $filas[$fila(null)]['compradores'] += $sinCiudad;
            }
        } else {
            unset($filas['sin']);
        }

        $ids = array_filter(array_column($filas, 'municipality_id'));
        $nombres = DB::table('municipalities as m')
            ->leftJoin('departments as d', 'd.id', '=', 'm.department_id')
            ->whereIn('m.id', $ids ?: [0])
            ->get(['m.id', 'm.name as ciudad', 'd.name as departamento'])
            ->keyBy('id');

        $lista = array_map(function (array $r) use ($nombres) {
            $n = $r['municipality_id'] === null ? null : ($nombres[$r['municipality_id']] ?? null);

            $porTipo = array_values($r['por_tipo']);
            usort($porTipo, fn ($a, $b) => $b['negocios'] <=> $a['negocios']);

            return [
                'municipality_id' => $r['municipality_id'],
                'ciudad' => $r['municipality_id'] === null ? 'Sin ciudad' : ($n->ciudad ?? 'Ciudad desconocida'),
                'departamento' => $n->departamento ?? null,
                'negocios' => $r['negocios'],
                'por_tipo' => $porTipo,
                'conjuntos' => $r['conjuntos'],
                'pedidos' => $r['pedidos'],
                'ventas' => round($r['ventas'], 2),
                'compradores' => $r['compradores'],
                'desde' => $r['desde'] ? Carbon::parse($r['desde'])->toDateString() : null,
            ];
        }, array_values($filas));

        // Las que más pesan primero; «Sin ciudad» siempre al final.
        usort($lista, fn ($a, $b) => [$a['municipality_id'] === null, -$a['negocios'], -$a['pedidos'], -$a['compradores'], $a['ciudad']]
            <=> [$b['municipality_id'] === null, -$b['negocios'], -$b['pedidos'], -$b['compradores'], $b['ciudad']]);

        return $lista;
    }

    /* ----------------------------- OPCIONES ----------------------------- */

    /**
     * Lo que ofrece la barra de filtros: las ciudades donde hay al menos un
     * negocio o un conjunto (de cualquier estado) y los tipos de negocio.
     * Sin filtrar, para que elegir una ciudad no haga desaparecer las demás
     * del selector.
     */
    private function opciones(): array
    {
        $ciudades = DB::table('municipalities as m')
            ->leftJoin('departments as d', 'd.id', '=', 'm.department_id')
            ->where(fn ($q) => $q
                ->whereIn('m.id', DB::table('business')->whereNotNull('municipality_id')->select('municipality_id'))
                ->orWhereIn('m.id', DB::table('residential_complexes')->whereNotNull('municipality_id')->select('municipality_id')))
            ->orderBy('m.name')
            ->get(['m.id', 'm.name', 'd.name as departamento'])
            ->map(fn ($c) => [
                'id' => (int) $c->id,
                'nombre' => (string) $c->name,
                'departamento' => $c->departamento,
            ])
            ->values()
            ->all();

        $tipos = DB::table('category_business')->orderBy('id')->get(['id', 'name'])
            ->map(fn ($t) => ['id' => (int) $t->id, ...$this->oficio((string) $t->name)])
            ->values()
            ->all();

        return ['ciudades' => $ciudades, 'tipos' => $tipos];
    }

    /* --------------------------- CONSULTAS BASE ------------------------- */

    /** Negocios activos con los filtros de ciudad y tipo. */
    private function negocios(array $f): Builder
    {
        return DB::table('business as b')
            ->where('b.state', 1)
            ->when($f['municipality_id'], fn ($q, $m) => $q->where('b.municipality_id', $m))
            ->when($f['tipo'], fn ($q, $t) => $q->where('b.type', $t));
    }

    /**
     * Pedidos entregados y con el pago confirmado, en un rango.
     *
     * El join contra `business` solo se agrega si un filtro o quien llama lo
     * necesita (`$conNegocio`), como en los reportes: cargarlo siempre haría
     * más lenta la consulta más común.
     */
    private function entregados(array $f, Carbon $desde, Carbon $hasta, bool $conNegocio = false): Builder
    {
        $q = DB::table('orderssales as o')
            ->where('o.state', OrdersSales::ENTREGADO)
            ->whereNotIn('o.payment_state', OrdersSales::SIN_PAGO)
            ->whereBetween('o.sale_date', [$desde, $hasta]);

        if ($conNegocio || $f['municipality_id'] || $f['tipo']) {
            $q->join('business as b', 'b.busines_id', '=', 'o.busines_id')
                ->when($f['municipality_id'], fn ($w, $m) => $w->where('b.municipality_id', $m))
                ->when($f['tipo'], fn ($w, $t) => $w->where('b.type', $t));
        }

        return $q;
    }

    /**
     * Cuentas de comprador. Con ciudad, las que tienen una dirección activa
     * ahí. El tipo de negocio no aplica: un comprador no es de un oficio.
     */
    private function compradores(array $f): Builder
    {
        return DB::table('user as u')
            ->where('u.rol', self::ROL_COMPRADOR)
            ->when($f['municipality_id'], fn ($q, $m) => $q->whereExists(
                fn ($s) => $s->from('user_address as ua')
                    ->whereColumn('ua.user_id', 'u.user_id')
                    ->where('ua.state', 1)
                    ->where('ua.municipality_id', $m)
                    ->selectRaw('1')
            ));
    }

    private function entero(mixed $v): ?int
    {
        return ($v === null || $v === '' || (int) $v <= 0) ? null : (int) $v;
    }
}
