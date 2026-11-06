<?php

namespace App\Http\Controllers\Negocio;

use App\Http\Controllers\Controller;
use App\Models\Domiciliary;
use App\Models\Operacion\DomiciliaryDocument;
use App\Models\Order\OrdersSales;
use App\Services\Ajustes;
use App\Services\CustodiaDeEfectivo;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * LA FICHA DE UN DOMICILIARIO, VISTA DESDE LA TIENDA.
 *
 * La lista contesta «a quien le puedo despachar AHORA». Esta contesta la otra
 * pregunta, la que se hace antes: «este senor deberia estar rodando?».
 *
 * SOLO LECTURA, Y RECORTADA A LO QUE ES DE ESTA TIENDA. Son dos limites
 * distintos y los dos importan:
 *
 *  1. El domiciliario reparte para varias tiendas. Sus entregas, su plata y
 *     los pedidos que lleva encima se cuentan SOLO sobre los de esta: los
 *     nombres y direcciones de los compradores de la tienda de enfrente no son
 *     asunto suyo. De los otros negocios sale el nombre y nada mas, porque el
 *     tendero necesita saber que la persona reparte el tiempo —es media
 *     explicacion de por que siempre esta con el cupo lleno— y eso lo ve igual
 *     cuando lo llama.
 *
 *  2. De los documentos sale la SITUACION y si hay archivo, no el archivo.
 *     Para despachar hace falta saber si tiene licencia y SOAT al dia. El
 *     archivo, si la tienda quiere verlo, se pide aparte a
 *     DocumentosDelDomiciliarioController@ver, que exige el vinculo.
 *
 * Y NO SE FIRMA NADA DESDE ACA. El acuerdo de vinculacion lo firma el
 * domiciliario desde SU sesion (`/domiciliaries/contrato`): capturar la firma
 * de una persona desde la pantalla de otra es justo lo que se quito de ese
 * flujo. Lo que la tienda ve es si esta firmado o no, que es lo que necesita
 * para decidir.
 */
class FichaDelDomiciliarioController extends Controller
{
    /** Los que el tendero reconoce, con el nombre que usa al pedirlos. */
    private const ROTULOS = [
        'cedula'        => ['Cédula', 'Las dos caras'],
        'licencia'      => ['Licencia de conducción', 'Categoría A2 para moto'],
        'soat'          => ['SOAT', 'Del seguro obligatorio'],
        'tecnomecanica' => ['Tecnomecánica', 'Revisión de la moto'],
        'arl'           => ['ARL', 'Riesgos laborales'],
        'eps'           => ['EPS', 'Afiliación en salud'],
    ];

    public function __construct(private readonly CustodiaDeEfectivo $custodia)
    {
    }

    public function show(Request $request, int $id)
    {
        $negocioId = (int) $request->attributes->get('busines_id');

        /*
         * El vinculo con ESTA tienda es la autorizacion. Sin este `whereHas`,
         * cualquier tendero podria pedir la ficha —con telefono, correo y
         * documentos— de un domiciliario que no reparte para el.
         */
        $d = Domiciliary::with(['user', 'businesses'])
            ->whereHas('businesses', fn ($q) => $q->where('business.busines_id', $negocioId)->where('business_domiciliary.state', 1))
            ->findOrFail($id);

        $max = (int) Ajustes::valor('operacion.entregas_simultaneas');

        $encima = OrdersSales::with('buyer.user')
            ->where('domiciliary_id', $d->domiciliary_id)
            ->where('busines_id', $negocioId)
            ->where('state', 3)
            ->orderByDesc('dispatched_at')
            ->get();

        return response()->json([
            'domiciliario' => [
                'domiciliary_id'    => $d->domiciliary_id,
                'name'              => $d->user?->name,
                'email'             => $d->user?->email,
                'phone'             => $d->user?->phone,
                'available'         => (bool) $d->available,
                'qualification'     => $d->qualification,
                'active_orders'     => $encima->count(),
                'max_active_orders' => $max,
                'cash_on_hand'      => round($this->custodia->saldo($d->domiciliary_id), 2),
            ],
            'actividad'  => $this->actividad($d->domiciliary_id, $negocioId),
            /* Lo que alimenta la vista de detalle: la semana contra la
               anterior, las entregas por dia, sus pedidos contigo, lo que le
               pagaste y el historial. Todo sale de los pedidos de ESTA tienda. */
            ...$this->detalle($d, $negocioId),
            /* Misma forma que `/domiciliaries/contrato`, que es de donde sale
               la verdad: el panel de la tienda y el telefono del domiciliario
               no deben contar lo mismo de dos maneras. */
            'acuerdo'    => [
                'firmado'    => $d->contract_signed_at !== null,
                'firmado_el' => $d->contract_signed_at,
                'ciudad'     => $d->contract_city,
            ],
            'documentos' => $this->documentos($d->domiciliary_id),
            /* Si puede recibir pedidos o qué le falta. Solo aplica a los que
               dio de alta una tienda (ver PapelesDelDomiciliario). */
            'despacho'   => (function () use ($d) {
                $papeles = app(\App\Services\PapelesDelDomiciliario::class);
                $faltan = $papeles->faltantes($d);

                return [
                    'exige_papeles' => $papeles->leAplica($d),
                    'bloqueado'     => (bool) $faltan,
                    'faltan'        => $faltan,
                    'texto'         => $faltan ? 'Le falta ' . $papeles->enPalabras($faltan) . '.' : null,
                ];
            })(),
            'negocios'   => $d->businesses->map(fn ($b) => [
                'busines_id' => $b->busines_id,
                'name'       => $b->name,
                'es_tuyo'    => (int) $b->busines_id === $negocioId,
            ])->values(),
            'encima' => $encima->map(fn ($o) => [
                'orderSales_id' => $o->orderSales_id,
                'cliente'       => $o->buyer?->user?->name,
                'total'         => $o->total,
                'cash_due'      => $o->cash_due,
                'methods_id'    => $o->methods_id,
                'dispatched_at' => $o->dispatched_at,
            ])->values(),
        ]);
    }

    /** El estado de un pedido cancelado. */
    private const CANCELADO = 5;

    /**
     * La vista de detalle del domiciliario.
     *
     * Los pedidos se leen UNA vez —los ultimos 200 suyos contigo— y de ahi
     * salen la semana, los dias, la tabla de pedidos, los pagos y la mitad del
     * historial. Las cuentas se hacen en PHP por lo mismo que el tiempo medio
     * de `actividad`: restar fechas en SQL se escribe distinto en MySQL y en
     * SQLite, y son pocas filas.
     */
    private function detalle(Domiciliary $d, int $negocioId): array
    {
        $pedidos = OrdersSales::with('buyer.user')
            ->where('domiciliary_id', $d->domiciliary_id)
            ->where('busines_id', $negocioId)
            ->orderByDesc('orderSales_id')
            ->limit(200)
            ->get();

        $entregados = $pedidos->where('state', 4)->filter(fn ($o) => $o->delivery_date);

        $lunes = now()->startOfWeek();
        $lunesPasado = $lunes->copy()->subWeek();
        $semana = fn ($desde, $hasta) => $entregados->filter(
            fn ($o) => $o->delivery_date >= $desde && $o->delivery_date < $hasta,
        );
        $resumirSemana = function ($lote) {
            $min = $lote->filter(fn ($o) => $o->dispatched_at)
                ->map(fn ($o) => $o->dispatched_at->diffInMinutes($o->delivery_date));

            return [
                'entregas' => $lote->count(),
                'pagado'   => round((float) $lote->sum('domiciliary_fee'), 2),
                'minutos'  => $min->isEmpty() ? null : round($min->avg(), 1),
            ];
        };

        $porDia = collect(range(6, 0))->map(function ($atras) use ($entregados) {
            $dia = now()->subDays($atras)->toDateString();

            return [
                'fecha'    => $dia,
                'entregas' => $entregados->filter(fn ($o) => $o->delivery_date->toDateString() === $dia)->count(),
            ];
        })->values();

        $movimientos = collect();
        foreach ($entregados as $o) {
            if ((float) $o->domiciliary_fee > 0) {
                $movimientos->push([
                    'tipo'     => 'domicilio',
                    'order_id' => $o->orderSales_id,
                    'valor'    => round((float) $o->domiciliary_fee, 2),
                    'fecha'    => $o->delivery_date,
                ]);
            }
            if ((float) $o->cash_due > 0) {
                $movimientos->push([
                    'tipo'     => 'efectivo',
                    'order_id' => $o->orderSales_id,
                    'valor'    => round((float) $o->cash_due, 2),
                    'fecha'    => $o->delivery_date,
                ]);
            }
        }

        return [
            'semana' => [
                'actual'   => $resumirSemana($semana($lunes, now()->addMinute())),
                'anterior' => $resumirSemana($semana($lunesPasado, $lunes)),
            ],
            'por_dia' => $porDia,
            'pedidos' => $pedidos->map(fn ($o) => [
                'orderSales_id' => $o->orderSales_id,
                'cliente'       => $o->buyer?->user?->name,
                'state'         => (int) $o->state,
                'methods_id'    => $o->methods_id,
                'payment_state' => $o->payment_state,
                'total'         => $o->total,
                'cash_due'      => $o->cash_due,
                'fee'           => $o->domiciliary_fee,
                'sale_date'     => $o->sale_date,
                'dispatched_at' => $o->dispatched_at,
                'delivery_date' => $o->delivery_date,
            ])->values(),
            'pagos' => [
                'pagado'           => round((float) $entregados->sum('domiciliary_fee'), 2),
                'efectivo_cobrado' => round((float) $entregados->sum('cash_due'), 2),
                'movimientos'      => $movimientos->sortByDesc(fn ($m) => (string) $m['fecha'])->take(60)->values(),
            ],
            'historial' => $this->historial($d, $pedidos),
        ];
    }

    /**
     * Lo que le ha pasado, en orden: pedidos que llevo, entrego o se
     * cancelaron, papeles que se le cargaron, el acuerdo. Sale de fechas que
     * ya existen; no hay una tabla de eventos aparte que pueda contradecirlas.
     */
    private function historial(Domiciliary $d, $pedidos): array
    {
        $eventos = collect();
        $plata = fn ($v) => '$' . number_format((float) $v, 0, ',', '.');

        foreach ($pedidos as $o) {
            $n = $o->orderSales_id;
            if ($o->dispatched_at) {
                $eventos->push(['tipo' => 'operacion', 'icono' => 'salio',
                    'texto' => "Salió con el pedido #{$n}", 'fecha' => $o->dispatched_at]);
            }
            if ((int) $o->state === 4 && $o->delivery_date) {
                $eventos->push(['tipo' => 'operacion', 'icono' => 'entrego',
                    'texto' => "Entregó el pedido #{$n}", 'fecha' => $o->delivery_date]);
                if ((float) $o->domiciliary_fee > 0) {
                    $eventos->push(['tipo' => 'pagos', 'icono' => 'pago',
                        'texto' => 'Ganó ' . $plata($o->domiciliary_fee) . " por el pedido #{$n}",
                        'fecha' => $o->delivery_date]);
                }
                if ((float) $o->cash_due > 0) {
                    $eventos->push(['tipo' => 'pagos', 'icono' => 'efectivo',
                        'texto' => 'Cobró ' . $plata($o->cash_due) . " en efectivo por el pedido #{$n}",
                        'fecha' => $o->delivery_date]);
                }
            }
            if ((int) $o->state === self::CANCELADO) {
                $eventos->push(['tipo' => 'operacion', 'icono' => 'cancelado',
                    'texto' => "Pedido #{$n} cancelado", 'fecha' => $o->updated_at ?? $o->dispatched_at ?? $o->sale_date]);
            }
        }

        DomiciliaryDocument::where('domiciliary_id', $d->domiciliary_id)
            ->get(['type', 'notes', 'created_at'])
            ->each(function ($doc) use ($eventos) {
                $rotulo = (self::ROTULOS[$doc->type] ?? [ucfirst($doc->type)])[0];
                $eventos->push(['tipo' => 'documentos', 'icono' => 'documento',
                    'texto' => "Se cargó {$rotulo}" . ($doc->notes ? " · {$doc->notes}" : ''),
                    'fecha' => $doc->created_at]);
            });

        if ($d->contract_signed_at) {
            $eventos->push(['tipo' => 'documentos', 'icono' => 'acuerdo',
                'texto' => 'Firmó el acuerdo de vinculación', 'fecha' => $d->contract_signed_at]);
        }

        return $eventos
            ->filter(fn ($e) => $e['fecha'])
            ->sortByDesc(fn ($e) => (string) $e['fecha'])
            ->take(80)
            ->values()
            ->all();
    }

    /**
     * Lo que ha hecho PARA ESTA TIENDA: entregas, lo que se le pago y cuanto
     * tarda. `domiciliary_fee` ya viene congelado en el pedido, asi que no hay
     * que recalcular ninguna comision.
     */
    private function actividad(int $domiciliaryId, int $negocioId): array
    {
        $base = fn () => OrdersSales::where('domiciliary_id', $domiciliaryId)
            ->where('busines_id', $negocioId)
            ->where('state', 4);

        $fila = $base()->first([
            DB::raw('COUNT(orderSales_id) as entregas'),
            DB::raw('COALESCE(SUM(domiciliary_fee), 0) as ganado'),
        ]);

        $hoy = $base()->whereDate('delivery_date', now()->toDateString())->first([
            DB::raw('COUNT(orderSales_id) as entregas'),
            DB::raw('COALESCE(SUM(domiciliary_fee), 0) as ganado'),
        ]);

        /*
         * El tiempo medio se saca en PHP y no en SQL a proposito: la resta de
         * dos marcas se escribe distinta en MySQL y en SQLite —y esa
         * diferencia ya dejo una pantalla sin poder probarse— y aca son como
         * mucho unos cientos de filas. La version en SQL vive en
         * `GeneradorDeReportes`, para los informes que si recorren miles.
         */
        $minutos = $base()
            ->whereNotNull('dispatched_at')
            ->whereNotNull('delivery_date')
            ->get(['dispatched_at', 'delivery_date'])
            ->map(fn ($o) => $o->dispatched_at->diffInMinutes($o->delivery_date));

        return [
            'entregas'       => (int) $fila->entregas,
            'ganado'         => round((float) $fila->ganado, 2),
            'entregas_hoy'   => (int) $hoy->entregas,
            'ganado_hoy'     => round((float) $hoy->ganado, 2),
            'minutos_medios' => $minutos->isEmpty() ? null : round($minutos->avg(), 1),
        ];
    }

    /**
     * Que papeles tiene y cuales no.
     *
     * Se listan TODOS los obligatorios, tenga o no fila: el que falta es
     * justamente el que hay que ver. Una lista que solo enseña lo subido
     * contesta «que tiene» cuando la pregunta es «que le falta».
     */
    private function documentos(int $domiciliaryId): array
    {
        $suyos = DomiciliaryDocument::where('domiciliary_id', $domiciliaryId)
            ->where('state', 1)
            ->get()
            ->keyBy('type');

        $tipos = array_values(array_unique(array_merge(
            ['cedula'],
            DomiciliaryDocument::OBLIGATORIOS,
            $suyos->keys()->all(),
        )));

        return collect($tipos)->map(function ($tipo) use ($suyos) {
            $doc = $suyos->get($tipo);
            [$rotulo, $pista] = self::ROTULOS[$tipo] ?? [ucfirst($tipo), null];

            return [
                'tipo'      => $tipo,
                'rotulo'    => $rotulo,
                'pista'     => $pista,
                'situacion' => $doc ? $doc->situacion() : 'falta',
                'vence_el'  => $doc?->expires_at?->toDateString(),
                'dias'      => $doc?->diasRestantes(),
                /* Si hay archivo que ver. El archivo en sí se pide aparte, a
                   `documentos/{tipo}/archivo`, con la sesión de la tienda. */
                'tiene_archivo' => (bool) $doc?->media_id,
                'es_pdf'        => $doc?->media_id
                    ? DB::table('media_files')->where('id', $doc->media_id)->value('mime_type') === 'application/pdf'
                    : false,
            ];
        })->values()->all();
    }
}
