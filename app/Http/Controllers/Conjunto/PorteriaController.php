<?php

declare(strict_types=1);

namespace App\Http\Controllers\Conjunto;

use App\Events\CodigoDeAccesoUsado;
use App\Http\Controllers\Controller;
use App\Http\Requests\Conjunto\VerificarEnPorteriaRequest;
use App\Http\Resources\Domiciliario\DomiciliarioEnLaPorteriaResource;
use App\Models\Conjunto\ComplexStaff;
use App\Models\Domiciliary;
use App\Services\AccesoAlConjunto;
use App\Services\PulsoDelConjunto;
use App\Support\Enmascarar;
use App\Support\FiltroDeFechas;
use App\Support\Like;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * La portería del conjunto.
 *
 * El celador escanea el código de la app del domiciliario —o escribe su
 * cédula— y esto responde una sola pregunta: ¿tiene pedidos para ESTE
 * conjunto?
 *
 * El conjunto NO llega por parámetro. Lo pone `EnsureComplexStaff` desde la
 * sesión. Si viniera en la petición, un celador podría consultar la portería
 * de otro edificio cambiando un número, y de paso vería a qué apartamento va
 * cada pedido de un conjunto que no es el suyo.
 */
class PorteriaController extends Controller
{
    public function __construct(
        private AccesoAlConjunto $acceso,
        private PulsoDelConjunto $pulso,
    ) {}

    public function verificar(VerificarEnPorteriaRequest $request)
    {
        $datos = $request->validated();

        $complexId = (int) $request->attributes->get('complex_id');

        $porCodigo = ! empty($datos['code']);
        $domiciliario = $porCodigo
            ? $this->acceso->resolverCodigo($datos['code'])
            : $this->acceso->resolverCedula($datos['document']);

        if (! $domiciliario) {
            return response()->json([
                'message' => $porCodigo
                    // Se distingue a propósito: un código caducado es lo más
                    // frecuente, y decir "no existe" haría que el celador
                    // buscara el problema donde no está.
                    ? 'El código no es válido o ya venció. Pídele que genere uno nuevo.'
                    : 'No encontramos un domiciliario con esa cédula.',
                'allowed' => false,
            ], 404);
        }

        /*
         * DESACTIVADO EN LA PLATAFORMA, NO PASA.
         *
         * Antes se miraban solo los pedidos: a un domiciliario dado de baja
         * con un pedido todavía en camino se le abría la puerta. Va antes de
         * los pedidos y sin ficha: a quien la plataforma retiró no se le
         * enseña nombre ni teléfono en la portería.
         */
        if ($this->acceso->estaDesactivado($domiciliario)) {
            return response()->json([
                'message' => 'Este domiciliario está desactivado en VeciPa\'Ya.',
                'allowed' => false,
                'reason' => 'domiciliario_inactivo',
            ]);
        }

        $pedidos = $this->acceso->pedidosEnElConjunto($domiciliario, $complexId);

        $conjunto = DB::table('residential_complexes')
            ->where('complex_id', $complexId)
            ->first(['name']);

        if ($pedidos->isEmpty()) {
            /*
             * NI SIQUIERA EL NOMBRE.
             *
             * La ficha completa —teléfono, calificación, historial— es para
             * quien tiene algo que hacer en este edificio. Y el nombre solo
             * también sobraba: escribir cédulas en la portería convertía el
             * panel en un buscador de «a quién pertenece este número». El
             * celador solo necesita saber que no pasa y por qué.
             */
            return response()->json([
                'message' => 'Este domiciliario no tiene pedidos en el conjunto',
                'allowed' => false,
                'reason' => 'sin_pedidos',
            ]);
        }

        // Solo se registra la entrada cuando procede: una entrada anotada de
        // alguien a quien no se dejó pasar ensuciaría el historial.
        $entrada = DB::table('complex_entries')->insertGetId([
            'complex_id' => $complexId,
            'domiciliary_id' => $domiciliario->domiciliary_id,
            'method' => $porCodigo ? 'codigo' : 'cedula',
            'orders_count' => $pedidos->count(),
            'orders' => $pedidos->toJson(),
            'registered_by' => $request->user()->user_id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        /*
         * Se le avisa a SU app, y sólo cuando entró por código.
         *
         * Con la cédula no hay nada que gastar —sirve siempre— así que un aviso
         * de «tu código se usó» sobre una entrada por cédula sería falso.
         *
         * Va después de registrar la entrada: si el aviso falla —Reverb caído,
         * red del servidor— la entrada ya está anotada. Al revés, un fallo de
         * notificación dejaría a alguien pasando sin registro.
         */
        if ($porCodigo) {
            try {
                CodigoDeAccesoUsado::dispatch(
                    (int) $domiciliario->user_id,
                    (string) ($conjunto?->name ?? 'el conjunto'),
                    $pedidos->count(),
                );
            } catch (\Throwable $e) {
                // Que no se pueda avisar no puede tumbar la portería: la
                // persona está en la puerta y el celador espera una respuesta.
                report($e);
            }
        }

        return response()->json([
            'message' => 'Puede entrar.',
            'allowed' => true,
            'entry_id' => $entrada,
            'domiciliary' => $this->ficha($domiciliario, $complexId),
            'orders' => $pedidos,
        ]);
    }

    /** Las entradas registradas en este conjunto. */
    /** Cuántas entradas por página, como mucho. El libro de un día puede pasar de mil. */
    public const POR_PAGINA_MAX = 100;

    public const POR_PAGINA = 50;

    /**
     * EL LIBRO, PAGINADO EN EL SERVIDOR.
     *
     * Antes devolvía las 300 primeras y el panel contaba y paginaba en el
     * navegador: en un día de 400 entradas las cifras de la cabecera salían
     * de un libro recortado sin que nadie lo supiera. Ahora la página es de
     * `per_page` (50, como mucho 100) y lo que se cuenta va aparte:
     *
     *   `cifras`   el DÍA entero (rango de fechas), sin buscador ni pestaña:
     *              son las de la cabecera y no cambian al escribir.
     *   `conteos`  cuántas hay en cada pestaña con el buscador puesto: el
     *              número junto a cada pestaña.
     *   `meta`     la página servida, sobre lo filtrado.
     */
    public function entradas(Request $request)
    {
        $complexId = (int) $request->attributes->get('complex_id');

        // Una fecha que no es fecha es un 422, no el 500 de `Carbon::parse()`.
        $request->validate([
            'desde' => 'nullable|date',
            'hasta' => 'nullable|date',
            'page' => 'nullable|integer|min:1',
            'per_page' => 'nullable|integer|min:1',
        ]);

        $porPagina = min(self::POR_PAGINA_MAX, max(1, (int) $request->query('per_page', self::POR_PAGINA)));
        $pagina = max(1, (int) $request->query('page', 1));

        // El día (o el rango): la base de todo lo que se cuenta.
        $delDia = function () use ($complexId, $request) {
            $q = DB::table('complex_entries as e')->where('e.complex_id', $complexId);

            /*
             * COMO RANGO, y el de `hasta` NO es la traduccion obvia: ver
             * `FiltroDeFechas::hasta()`. `date(col) <= '2026-11-05'` incluye
             * todo el dia 5; escribir `col <= '2026-11-05'` lo recorta a las
             * 00:00:00 y se pierde el dia entero menos el primer segundo, sin
             * un error: solo un numero mas pequenio.
             */
            if ($desde = $request->query('desde')) {
                FiltroDeFechas::desde($q, 'e.created_at', $desde);
            }

            if ($hasta = $request->query('hasta')) {
                FiltroDeFechas::hasta($q, 'e.created_at', $hasta);
            }

            return $q;
        };

        // El día con el buscador: sobre esto se cuentan las pestañas.
        $buscado = function () use ($delDia, $request) {
            $q = $delDia()
                ->leftJoin('domiciliary as d', 'd.domiciliary_id', '=', 'e.domiciliary_id')
                ->leftJoin('user as u', 'u.user_id', '=', 'd.user_id');

            if ($buscar = trim((string) $request->query('search'))) {
                $q->where(function ($q) use ($buscar) {
                    // Las visitas también se buscan: el libro es uno solo.
                    // Escapado: un `%` o un `_` del buscador no son comodines.
                    Like::contiene($q, 'u.name', $buscar);
                    Like::contiene($q, 'd.document', $buscar, 'or');
                    Like::contiene($q, 'e.visitor_name', $buscar, 'or');
                    Like::contiene($q, 'e.visitor_document', $buscar, 'or');
                });
            }

            return $q;
        };

        $consulta = $buscado()
            // Quién estaba en la portería cuando pasó. Es la mitad del valor
            // de un registro de entradas: sin eso dice qué ocurrió y no bajo
            // la responsabilidad de quién.
            ->leftJoin('user as c', 'c.user_id', '=', 'e.registered_by')
            ->leftJoin('user as x', 'x.user_id', '=', 'e.exit_registered_by');

        if ($metodo = $request->query('method')) {
            if (in_array($metodo, ['codigo', 'cedula', 'manual'], true)) {
                $consulta->where('e.method', $metodo);
            }
        }

        // Domiciliarios de la plataforma o gente de fuera. Son dos libros
        // distintos que comparten tabla, y casi siempre se consulta uno.
        if ($clase = $request->query('kind')) {
            if ($clase === 'externos') {
                $consulta->where('e.kind', '!=', 'domiciliario');
            } else {
                $consulta->where('e.kind', $clase);
            }
        }

        // Quien sigue adentro. Es la consulta que convierte el libro en
        // control de acceso.
        if ($request->boolean('dentro')) {
            $consulta->whereNull('e.exited_at');
        }

        $total = (clone $consulta)->count();

        $filas = $consulta
            ->orderByDesc('e.created_at')
            ->orderByDesc('e.id')
            ->forPage($pagina, $porPagina)
            ->get([
                'e.id', 'e.kind', 'e.method', 'e.orders_count', 'e.created_at',
                'e.exited_at',
                // Los del visitante. Van junto a los del domiciliario porque
                // el listado es uno solo: la portería no lleva dos libros.
                'e.visitor_name', 'e.visitor_document', 'e.visitor_phone',
                'e.visitor_company', 'e.vehicle_plate',
                'e.tower', 'e.apartment', 'e.authorized_by', 'e.notes',
                // El detalle guardado al entrar: a qué torre y apartamento iba
                // cada pedido. Se congeló en ese momento a propósito — si se
                // recalculara, un pedido entregado después ya no aparecería y
                // el registro de una entrada pasada cambiaría solo.
                'e.orders',
                'u.name as domiciliary_name', 'd.document',
                'c.name as registered_by_name',
                'x.name as exit_registered_by_name',
            ]);

        /*
         * EL CELADOR VE LOS DATOS ENMASCARADOS.
         *
         * El libro guarda cédula y teléfono de cada visitante y la cédula de
         * cada domiciliario. Al administrador le hacen falta completos —es
         * quien responde por el registro—; al celador le basta con los cuatro
         * últimos para reconocer a quien tiene delante, y así el turno de
         * noche no se lleva en el teléfono un directorio de todo el que pasó.
         */
        if ($request->attributes->get('complex_role') !== ComplexStaff::DUENO) {
            $filas->transform(function ($f) {
                $f->visitor_document = Enmascarar::ultimos4($f->visitor_document);
                $f->visitor_phone = Enmascarar::ultimos4($f->visitor_phone);
                $f->document = Enmascarar::ultimos4($f->document);

                return $f;
            });
        }

        // Por pestaña, con el buscador puesto: una sola consulta agrupada.
        $porClase = $buscado()
            ->groupBy('e.kind')
            ->get([
                'e.kind',
                DB::raw('COUNT(*) as n'),
                DB::raw('SUM(CASE WHEN e.exited_at IS NULL THEN 1 ELSE 0 END) as dentro'),
            ]);

        $conteos = [
            'todas' => (int) $porClase->sum('n'),
            'dentro' => (int) $porClase->sum('dentro'),
        ];
        foreach (['domiciliario', 'visitante', 'servicio', 'domicilio_externo'] as $k) {
            $conteos[$k] = (int) ($porClase->firstWhere('kind', $k)->n ?? 0);
        }

        return response()->json([
            'data' => $filas,
            'meta' => [
                'page' => $pagina,
                'per_page' => $porPagina,
                'total' => $total,
                'last_page' => max(1, (int) ceil($total / $porPagina)),
            ],
            'conteos' => $conteos,
            'cifras' => $this->cifrasDelDia($delDia()),
        ]);
    }

    /**
     * Las cifras de la cabecera, sobre el día entero.
     *
     * El tiempo adentro se calcula en PHP y no en SQL: la resta de fechas se
     * escribe distinto en MySQL y en SQLite, y un día de portería son unos
     * cientos de filas, no millones.
     */
    private function cifrasDelDia(Builder $consulta): array
    {
        $filas = $consulta->get(['e.kind', 'e.method', 'e.created_at', 'e.exited_at']);
        $domis = $filas->where('kind', 'domiciliario');

        $minutos = $domis->filter(fn ($f) => $f->exited_at)
            ->map(fn ($f) => (strtotime((string) $f->exited_at) - strtotime((string) $f->created_at)) / 60)
            ->filter(fn ($m) => $m >= 0);

        return [
            'total' => $filas->count(),
            'dentro' => $filas->whereNull('exited_at')->count(),
            'domiciliarios' => $domis->count(),
            'por_cedula' => $domis->where('method', 'cedula')->count(),
            'promedio_minutos' => $minutos->isEmpty() ? null : (int) round($minutos->avg()),
        ];
    }

    /**
     * Quién es quien está en la puerta.
     *
     * Lleva más de lo que hace falta para decidir si pasa —eso ya lo decidió
     * la consulta de pedidos— porque la decisión no siempre es automática. Un
     * celador que ve «primera vez que entra» mira con más cuidado que uno que
     * ve «lleva 40 entradas», y sin el dato las dos situaciones se ven
     * exactamente igual.
     *
     * La calificación y el historial son del DOMICILIARIO, no del comprador:
     * acá no se expone nada de quien pidió.
     */
    private function ficha(Domiciliary $d, int $complexId): array
    {
        return (new DomiciliarioEnLaPorteriaResource(
            $d,
            historial: $this->pulso->historialEnElConjunto($complexId, (int) $d->domiciliary_id),
        ))->resolve();
    }
}
