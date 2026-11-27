<?php

declare(strict_types=1);

namespace App\Services\Inversionista;

use App\Models\Order\OrdersSales;
use App\Services\GeneradorDeReportes;
use Carbon\Carbon;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * LA EMPRESA EN CIFRAS, SIN UNA SOLA PERSONA: LO COMÚN A LOS SIETE MÓDULOS.
 *
 * El panel del inversionista responde «¿cómo va VeciPa'Ya?» para quien puso
 * la plata y no opera nada, repartido en módulos (Resumen, Negocios,
 * Expansión, Usuarios, Operación, Ingresos e Informes). Cada módulo es una
 * clase hija de esta, y todas comparten lo que tiene que decir lo mismo en
 * todas partes: la ventana de fechas, los filtros, qué es un pedido, qué es
 * un negocio activo y cómo se compara contra el periodo anterior.
 *
 * SOLO AGREGADOS. Ninguna consulta selecciona hacia la respuesta un nombre,
 * un teléfono, un correo ni el identificador de una persona, de un negocio o
 * de un pedido: todo sale de `COUNT`, `SUM`, `AVG` y `MIN` agrupados. Los
 * únicos identificadores que viajan son los de ciudades y tipos de negocio,
 * que son catálogos. Hay una prueba que lo vigila endpoint por endpoint
 * (`tests/Feature/Inversionista`).
 *
 * LAS DEFINICIONES, en un solo sitio:
 *
 *  · Pedido = entregado (`state` 4) y con el pago confirmado (fuera los que
 *    esperan un pago en línea o lo tienen rechazado). Un pedido en curso puede
 *    cancelarse todavía y uno sin pagar no es plata: contarlos inflaría las
 *    cifras que más se van a repetir en una junta. Se fecha por `sale_date`.
 *  · Ventas (GMV) = suma de `total` de esos pedidos.
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
abstract class BaseDelInversionista
{
    /** Cuenta de comprador en la tabla `rol`. */
    protected const ROL_COMPRADOR = 1;

    protected const MESES = ['ene', 'feb', 'mar', 'abr', 'may', 'jun', 'jul', 'ago', 'sep', 'oct', 'nov', 'dic'];

    public function __construct(protected GeneradorDeReportes $reportes) {}

    /**
     * Lo que devuelve el endpoint del módulo: el periodo, los filtros y sus
     * datos.
     *
     * @param  array{desde?:?string, hasta?:?string, municipality_id?:?int, tipo?:?int}  $pedido
     */
    public function para(array $pedido): array
    {
        [$v, $f] = $this->contexto($pedido);

        return ['periodo' => $this->periodo($v), 'filtros' => $f] + self::segunElInterruptor($this->datos($v, $f));
    }

    /* --------------------------- LA PLATA TOTAL --------------------------- */

    /**
     * Las claves de PLATA TOTAL: lo vendido (`ventas`, también dentro de
     * `variacion`), el ingreso de la plataforma, las comisiones, los
     * subsidios y lo de los domicilios.
     *
     * Los PROMEDIOS no están, a propósito: `ticket_promedio`,
     * `ventas_por_negocio` (lo que vende, en promedio, un negocio que
     * vendió) y la parte de cada tipo (`participacion_ventas`, un
     * porcentaje) se ven siempre.
     */
    public const TOTALES_DE_PLATA = [
        'ventas', 'ingreso', 'comisiones', 'subsidios', 'margen_domicilio',
        'domicilios', 'pago_domiciliarios',
    ];

    /** ¿El inversionista ve la plata total? Ver `config/inversionista.php`. */
    public static function verIngresos(): bool
    {
        return (bool) config('inversionista.ver_ingresos', false);
    }

    /**
     * Los datos tal cual con el interruptor encendido; sin los totales de
     * plata, a cualquier profundidad, con él apagado.
     */
    public static function segunElInterruptor(array $datos): array
    {
        return self::verIngresos() ? $datos : self::sinTotalesDePlata($datos);
    }

    private static function sinTotalesDePlata(array $datos): array
    {
        $limpio = [];

        foreach ($datos as $clave => $valor) {
            if (is_string($clave) && in_array($clave, self::TOTALES_DE_PLATA, true)) {
                continue;
            }

            $limpio[$clave] = is_array($valor) ? self::sinTotalesDePlata($valor) : $valor;
        }

        return $limpio;
    }

    /** Los datos propios del módulo, para una ventana y unos filtros ya resueltos. */
    abstract public function datos(array $v, array $f): array;

    /**
     * La ventana y los filtros.
     *
     * La ventana es la de los reportes del panel interno: tope de 366 días,
     * fechas al revés enderezadas y el periodo anterior de igual tamaño. Se le
     * pasa como `from`/`to`, que es lo que lee.
     *
     * @return array{0: array, 1: array{municipality_id:?int, tipo:?int}}
     */
    public function contexto(array $pedido): array
    {
        $v = $this->reportes->ventanaDelReporte(new Request(array_filter([
            'from' => $pedido['desde'] ?? null,
            'to' => $pedido['hasta'] ?? null,
        ])));

        $f = [
            'municipality_id' => $this->entero($pedido['municipality_id'] ?? null),
            'tipo' => $this->entero($pedido['tipo'] ?? null),
        ];

        return [$v, $f];
    }

    public function periodo(array $v): array
    {
        return [
            'desde' => $v['desde']->toDateString(),
            'hasta' => $v['hasta']->toDateString(),
            'dias' => $v['dias'],
            'desde_anterior' => $v['desde_antes']->toDateString(),
            'hasta_anterior' => $v['hasta_antes']->toDateString(),
        ];
    }

    /* ------------------------------ CIFRAS ------------------------------ */

    protected function cifra(int|float|null $valor, int|float|null $anterior): array
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
    protected function variacion(int|float|null $actual, int|float|null $anterior): ?float
    {
        if ($actual === null || $anterior === null || (float) $anterior === 0.0) {
            return null;
        }

        return round((($actual - $anterior) / abs($anterior)) * 100, 1);
    }

    /** Un porcentaje con un decimal, o null si la base es cero. */
    protected function porcentaje(int|float $parte, int|float $base): ?float
    {
        return (float) $base === 0.0 ? null : round($parte / $base * 100, 1);
    }

    /** Un cociente con un decimal, o null si la base es cero. */
    protected function cociente(int|float $a, int|float $b, int $decimales = 1): ?float
    {
        return (float) $b === 0.0 ? null : round($a / $b, $decimales);
    }

    /* ----------------------------- OFICIOS ------------------------------ */

    /**
     * Cómo se llama cada oficio en pantalla, y su clave.
     *
     * El catálogo dice «Farmacia» y «Tienda de repuesto»; la app y el panel
     * dicen «Droguería» y «Repuestos». La clave es la de las ilustraciones
     * del panel (`public/marca`).
     *
     * @return array{clave:string, nombre:string}
     */
    protected function oficio(string $nombre): array
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

    /**
     * El catálogo de tipos con su nombre de pantalla, en su orden.
     *
     * @return list<array{id:int, clave:string, nombre:string}>
     */
    protected function tipos(): array
    {
        return DB::table('category_business')->orderBy('id')->get(['id', 'name'])
            ->map(fn ($t) => ['id' => (int) $t->id, ...$this->oficio((string) $t->name)])
            ->values()
            ->all();
    }

    /**
     * Lo que ofrece la barra de filtros: las ciudades donde hay al menos un
     * negocio o un conjunto (de cualquier estado) y los tipos de negocio.
     * Sin filtrar, para que elegir una ciudad no haga desaparecer las demás
     * del selector.
     */
    public function opciones(): array
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

        return ['ciudades' => $ciudades, 'tipos' => $this->tipos()];
    }

    /* ------------------------------ MESES ------------------------------- */

    /**
     * Los meses de una serie: los doce que terminan en el mes de `hasta`, más
     * `$antes` meses previos que sirven de base para la primera variación.
     *
     * No depende del periodo elegido salvo en dónde termina: el crecimiento
     * se lee siempre a un año vista.
     *
     * @return array{0: Carbon, 1: Carbon, 2: list<array{mes:string, etiqueta:string, anio:int, en_curso:bool, inicio:Carbon, fin:Carbon}>}
     */
    protected function mesesHasta(Carbon $hasta, int $antes = 0): array
    {
        $fin = $hasta->copy()->endOfMonth();
        $inicio = $hasta->copy()->startOfMonth()->subMonths(11 + $antes);
        $hoy = Carbon::today();
        $meses = [];

        for ($m = $inicio->copy(); $m->lte($fin); $m->addMonth()) {
            $meses[] = [
                'mes' => $m->format('Y-m'),
                'etiqueta' => self::MESES[$m->month - 1],
                'anio' => $m->year,
                // El mes en curso todavía no termina: compararlo entero contra
                // uno cerrado siempre lo haría ver como una caída.
                'en_curso' => $m->isSameMonth($hoy),
                'inicio' => $m->copy()->startOfMonth(),
                'fin' => $m->copy()->endOfMonth(),
            ];
        }

        return [$inicio, $fin, $meses];
    }

    /** Lo que se publica de un mes (sin las fechas de trabajo). */
    protected function rotuloDeMes(array $m): array
    {
        return ['mes' => $m['mes'], 'etiqueta' => $m['etiqueta'], 'anio' => $m['anio'], 'en_curso' => $m['en_curso']];
    }

    /**
     * `AAAA-MM` de una fecha, en el dialecto del motor.
     *
     * `DATE_FORMAT` es de MySQL y las pruebas corren sobre SQLite: sin esto
     * la serie funciona en producción y revienta justo donde se comprueba.
     */
    protected function mesDe(string $col): string
    {
        return $this->esMysql()
            ? "DATE_FORMAT({$col}, '%Y-%m')"
            : "strftime('%Y-%m', {$col})";
    }

    /** Minutos entre dos columnas de fecha, en el dialecto del motor. */
    protected function minutosEntre(string $a, string $b): string
    {
        return $this->esMysql()
            ? "TIMESTAMPDIFF(MINUTE, {$a}, {$b})"
            // Redondeado: el punto flotante de SQLite deja 30 minutos en
            // 29,9999 y un pedido justo a tiempo saldría tarde.
            : "ROUND((julianday({$b}) - julianday({$a})) * 1440)";
    }

    private function esMysql(): bool
    {
        return in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true);
    }

    /* --------------------------- CONSULTAS BASE ------------------------- */

    /** Negocios activos con los filtros de ciudad y tipo. */
    protected function negocios(array $f): Builder
    {
        return DB::table('business as b')
            ->where('b.state', 1)
            ->when($f['municipality_id'], fn ($q, $m) => $q->where('b.municipality_id', $m))
            ->when($f['tipo'], fn ($q, $t) => $q->where('b.type', $t));
    }

    /** Negocios activos que ya existían al corte (los sin fecha, siempre). */
    protected function negociosAl(array $f, Carbon $corte): Builder
    {
        return $this->negocios($f)->where(
            fn ($w) => $w->whereNull('b.created_at')->orWhere('b.created_at', '<=', $corte)
        );
    }

    /**
     * Pedidos entregados y con el pago confirmado, en un rango.
     *
     * El join contra `business` solo se agrega si un filtro o quien llama lo
     * necesita (`$conNegocio`), como en los reportes: cargarlo siempre haría
     * más lenta la consulta más común.
     */
    protected function entregados(array $f, Carbon $desde, Carbon $hasta, bool $conNegocio = false): Builder
    {
        return $this->pedidos($f, $desde, $hasta, $conNegocio)
            ->where('o.state', OrdersSales::ENTREGADO)
            ->whereNotIn('o.payment_state', OrdersSales::SIN_PAGO);
    }

    /**
     * TODOS los pedidos hechos en un rango, en cualquier estado, con los
     * filtros de ciudad y tipo del negocio. Es la base de la tasa de
     * cancelación: los cancelados sobre todo lo que se pidió.
     */
    protected function pedidos(array $f, Carbon $desde, Carbon $hasta, bool $conNegocio = false): Builder
    {
        $q = DB::table('orderssales as o')->whereBetween('o.sale_date', [$desde, $hasta]);

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
    protected function compradores(array $f): Builder
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

    /** Cuentas de comprador que ya existían al corte (las sin fecha, siempre). */
    protected function compradoresAl(array $f, Carbon $corte): Builder
    {
        return $this->compradores($f)->where(
            fn ($w) => $w->whereNull('u.created_at')->orWhere('u.created_at', '<=', $corte)
        );
    }

    protected function entero(mixed $v): ?int
    {
        return ($v === null || $v === '' || (int) $v <= 0) ? null : (int) $v;
    }

    /** Pesos con dos decimales a partir de lo que devuelva la base. */
    protected function pesos(mixed $v): float
    {
        return round((float) ($v ?? 0), 2);
    }
}
