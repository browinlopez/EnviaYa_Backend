<?php

namespace App\Services;

use App\Models\Area;
use App\Models\Operacion\DomiciliaryDocument;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * QUÉ TIENE QUE ATENDER HOY CADA ÁREA
 *
 * El panel sabía desde el principio que hay un SOAT vencido, una PQRS fuera de
 * plazo y un corte sin pagar. El problema era que solo lo decía si alguien
 * abría la pantalla: la información existía y no llegaba a nadie.
 *
 * Esto la empaqueta para poder mandarla. Dos reglas:
 *
 * 1. Solo lo ACCIONABLE. No va "tienes 99 pedidos" ni "el mes lleva 9 millones":
 *    eso es contexto, y en un aviso diario el contexto es relleno que enseña a
 *    ignorar el aviso. Va lo que está mal AHORA y no se arregla solo.
 *
 * 2. Si un área no tiene nada pendiente, NO se le escribe. Un correo diario que
 *    la mitad de los días dice "todo en orden" se convierte en un correo que
 *    nadie abre, y el día que traiga algo tampoco se abrirá.
 */
class ResumenDeArea
{
    /**
     * @return array<string, array{area: Area, asuntos: list<array>}>
     *         indexado por código de área; solo las que tienen algo
     */
    public function paraTodas(): array
    {
        $salida = [];

        foreach (Area::where('state', 1)->orderBy('id')->get() as $area) {
            $asuntos = $this->asuntosDe($area);

            if ($asuntos !== []) {
                $salida[$area->code] = ['area' => $area, 'asuntos' => $asuntos];
            }
        }

        return $salida;
    }

    /**
     * Áreas que SUPERVISAN: reciben lo urgente de todas, no solo lo propio.
     *
     * Gerencia por definición —ve el negocio entero para decidir— y Tecnología
     * porque es quien tiene que enterarse si algo del sistema se atascó.
     */
    private const SUPERVISAN = ['gerencia', 'sistema'];

    /**
     * Los pendientes que le corresponden a un área.
     *
     * DOS filtros, y hacen falta los dos:
     *
     * 1. El PERMISO, que es el de seguridad: no se le cuenta a nadie algo de
     *    una sección que no puede abrir.
     *
     * 2. La RESPONSABILIDAD, que es el de relevancia. Con solo el permiso,
     *    "pedidos atascados" le llegaba a cinco áreas —todas pueden ver las
     *    órdenes— y eso es precisamente lo que hace que un aviso se ignore: si
     *    le llega a todos, nadie lo siente suyo y nadie lo atiende.
     *
     * Las áreas que supervisan son la excepción: reciben lo URGENTE de todas
     * las demás, porque su trabajo es enterarse. Lo no urgente de otra área no,
     * que es el relleno que convierte un resumen en correo basura.
     *
     * @return list<array{clave:string, titulo:string, detalle:string, cuantos:int, urgente:bool, ruta:string, ajeno:bool}>
     */
    public function asuntosDe(Area $area): array
    {
        $permisos = $area->permisos(Area::NIVEL_GESTOR);
        $ve = fn (string $modulo) => ($permisos[$modulo]['view'] ?? false) === true;

        $supervisa = in_array($area->code, self::SUPERVISAN, true);
        $asuntos = [];

        foreach ($this->catalogo() as $asunto) {
            if (!$ve($asunto['modulo'])) {
                continue;
            }

            $propio = $asunto['responsable'] === $area->code;

            if (!$propio && !($supervisa && $asunto['urgente'])) {
                continue;
            }

            $cuantos = ($asunto['contar'])();

            if ($cuantos > 0) {
                $asuntos[] = [
                    'clave'   => $asunto['clave'],
                    'titulo'  => $asunto['titulo'],
                    'detalle' => ($asunto['detalle'])($cuantos),
                    'cuantos' => $cuantos,
                    'urgente' => $asunto['urgente'],
                    'ruta'    => $asunto['ruta'],
                    // Para poder decir en el correo de quién es: a Gerencia le
                    // llega el asunto de SST, y sin esa marca parecería suyo.
                    'ajeno'   => !$propio,
                    // El NOMBRE del área, no su código: "Lo atiende sst" se lee
                    // como un error de la plantilla.
                    'de'      => $this->nombreDeArea($asunto['responsable']),
                ];
            }
        }

        /*
         * Lo propio antes que lo ajeno, lo urgente antes que el resto, y dentro
         * de eso lo que más pesa.
         *
         * Se arma una clave por elemento y se comparan las dos claves. Mezclar
         * `$a` y `$b` dentro del mismo arreglo —para invertir un criterio— es
         * fácil de escribir y de leer mal: así salía lo no urgente antes que lo
         * urgente. Para invertir se niega el valor, no se cruzan los operandos.
         */
        $clave = fn (array $x) => [
            (int) $x['ajeno'],      // propio (0) antes que ajeno (1)
            (int) !$x['urgente'],   // urgente (0) antes que el resto (1)
            -$x['cuantos'],         // y de mayor a menor
        ];

        usort($asuntos, fn ($a, $b) => $clave($a) <=> $clave($b));

        return $asuntos;
    }

    /** @var array<string,string> caché de código => nombre */
    private array $nombres = [];

    private function nombreDeArea(string $codigo): string
    {
        if (!isset($this->nombres[$codigo])) {
            $this->nombres[$codigo] = (string) (Area::where('code', $codigo)->value('name') ?? $codigo);
        }

        return $this->nombres[$codigo];
    }

    /**
     * El catálogo de lo que se vigila.
     *
     * Cada entrada dice de qué módulo depende —para no contarle a nadie algo
     * que no puede abrir—, cómo se cuenta y cómo se redacta. Agregar una alerta
     * es agregar una entrada acá, no tocar el comando ni la plantilla.
     */
    private function catalogo(): array
    {
        $hoy = Carbon::today();

        return [
            /* ------------------------------- SST ------------------------------ */
            [
                'clave'       => 'documentos_vencidos',
                'responsable' => 'sst',
                'modulo'  => 'sst.documentos',
                'titulo'  => 'Documentación vencida',
                'urgente' => true,
                'ruta'    => '/sst/documentos',
                'contar'  => fn () => DB::table('domiciliary_documents')
                    ->where('state', 1)
                    ->whereDate('expires_at', '<', $hoy)
                    ->count(),
                'detalle' => fn ($n) => $n === 1
                    ? 'Un domiciliario está rodando con un documento vencido.'
                    : "{$n} documentos vencidos: esos domiciliarios están rodando sin respaldo.",
            ],
            [
                'clave'       => 'documentos_por_vencer',
                'responsable' => 'sst',
                'modulo'  => 'sst.documentos',
                'titulo'  => 'Documentación por vencer',
                'urgente' => false,
                'ruta'    => '/sst/documentos',
                'contar'  => fn () => DB::table('domiciliary_documents')
                    ->where('state', 1)
                    ->whereDate('expires_at', '>=', $hoy)
                    ->whereDate('expires_at', '<=', $hoy->copy()->addDays(DomiciliaryDocument::AVISO_DIAS))
                    ->count(),
                'detalle' => fn ($n) => "{$n} vencen en los próximos "
                    . DomiciliaryDocument::AVISO_DIAS . ' días. Renovarlos ahora evita parar a alguien.',
            ],
            [
                'clave'       => 'incidentes_abiertos',
                'responsable' => 'sst',
                'modulo'  => 'sst.incidentes',
                'titulo'  => 'Incidentes sin cerrar',
                'urgente' => true,
                'ruta'    => '/sst/incidentes',
                'contar'  => fn () => DB::table('safety_incidents')
                    ->whereIn('state', [0, 1])
                    ->where('severity', 'grave')
                    ->count(),
                'detalle' => fn ($n) => "{$n} incidente(s) de gravedad alta siguen abiertos.",
            ],
            [
                'clave'       => 'sin_acuerdo',
                'responsable' => 'sst',
                'modulo'  => 'domiciliarios',
                'titulo'  => 'Domiciliarios sin acuerdo firmado',
                'urgente' => true,
                'ruta'    => '/domiciliarios',
                'contar'  => fn () => DB::table('domiciliary')
                    ->where('state', 1)
                    ->whereNull('contract_signed_at')
                    ->count(),
                'detalle' => fn ($n) => "{$n} repartidor(es) activos sin acuerdo de vinculación firmado.",
            ],

            /* ----------------------------- CALIDAD ---------------------------- */
            [
                'clave'       => 'pqrs_vencidas',
                'responsable' => 'calidad',
                'modulo'  => 'pqrs',
                'titulo'  => 'PQRS fuera de plazo',
                'urgente' => true,
                'ruta'    => '/pqrs',
                'contar'  => fn () => DB::table('pqrs')
                    ->whereIn('state', [0, 1])
                    ->whereNotNull('due_at')
                    ->where('due_at', '<', Carbon::now())
                    ->count(),
                'detalle' => fn ($n) => $n === 1
                    ? 'Una persona lleva esperando más de lo prometido.'
                    : "{$n} personas llevan esperando más de lo prometido.",
            ],
            [
                'clave'       => 'resenas_negativas',
                'responsable' => 'calidad',
                'modulo'  => 'resenas',
                'titulo'  => 'Reseñas negativas nuevas',
                'urgente' => false,
                'ruta'    => '/resenas',
                'contar'  => function () {
                    $desde = Carbon::now()->subDay();

                    return DB::table('business_reviews')
                        ->where('created_at', '>=', $desde)
                        ->where('qualification', '<=', 2)
                        ->count()
                        + DB::table('domiciliary_reviews')
                            ->where('created_at', '>=', $desde)
                            ->where('qualification', '<=', 2)
                            ->count();
                },
                'detalle' => fn ($n) => "{$n} reseña(s) de dos estrellas o menos en las últimas 24 horas.",
            ],

            /* --------------------------- OPERACIÓN ---------------------------- */
            [
                'clave'       => 'pedidos_atascados',
                'responsable' => 'sistema',
                'modulo'  => 'ordenes',
                'titulo'  => 'Pedidos atascados',
                'urgente' => true,
                'ruta'    => '/ordenes',
                'contar'  => fn () => DB::table('orderssales')
                    ->whereIn('state', [1, 2, 3])
                    ->where(function ($q) {
                        $q->where(fn ($s) => $s->where('state', 2)->whereNull('domiciliary_id'))
                            ->orWhere('sale_date', '<', Carbon::now()->subDay());
                    })
                    ->count(),
                'detalle' => fn ($n) => "{$n} pedido(s) llevan más de un día sin avanzar o están despachados sin repartidor.",
            ],

            /* -------------------------- CONTABILIDAD -------------------------- */
            [
                'clave'       => 'cortes_por_pagar',
                'responsable' => 'contabilidad',
                'modulo'  => 'liquidaciones',
                'titulo'  => 'Cortes aprobados sin pagar',
                'urgente' => true,
                'ruta'    => '/liquidaciones',
                'contar'  => fn () => DB::table('settlements')->where('state', 1)->count(),
                'detalle' => function ($n) {
                    $monto = (float) DB::table('settlements')->where('state', 1)->sum('net_payable');

                    return "{$n} corte(s) aprobados y sin transferir, por $"
                        . number_format($monto, 0, ',', '.') . '.';
                },
            ],
            [
                'clave'       => 'pagos_rechazados',
                'responsable' => 'contabilidad',
                'modulo'  => 'pagos',
                'titulo'  => 'Cobros rechazados',
                'urgente' => false,
                'ruta'    => '/pagos',
                'contar'  => fn () => DB::table('payments')
                    ->whereIn('status', ['rejected', 'failed'])
                    ->where('payment_date', '>=', Carbon::now()->subDay())
                    ->count(),
                'detalle' => fn ($n) => "{$n} cobro(s) no entraron en las últimas 24 horas.",
            ],

            /* ---------------------------- COMERCIAL --------------------------- */
            [
                'clave'       => 'negocios_invisibles',
                'responsable' => 'comercial',
                'modulo'  => 'negocios',
                'titulo'  => 'Negocios que no se ven en la app',
                'urgente' => false,
                'ruta'    => '/negocios',
                // Dos formas de estar dado de alta y ser invisible: sin
                // coordenadas no entra en el mapa de entregas, y sin tipo no
                // aparece en ningún carrusel.
                'contar'  => fn () => DB::table('business')
                    ->where('state', 1)
                    ->where(function ($q) {
                        $q->whereNull('type')
                            ->orWhereNull('latitude')
                            ->orWhereNull('longitude');
                    })
                    ->count(),
                'detalle' => fn ($n) => "{$n} negocio(s) publicados sin tipo o sin coordenadas: están de alta y no se ven.",
            ],

            /* ---------------------------- MARKETING --------------------------- */
            [
                'clave'       => 'piezas_sin_imagen',
                'responsable' => 'marketing',
                'modulo'  => 'marketing.banners',
                'titulo'  => 'Piezas activas sin imagen',
                'urgente' => true,
                'ruta'    => '/marketing/banners',
                // Se guarda bien y no se muestra: el fallo más fácil de cometer
                // y el más difícil de notar.
                'contar'  => fn () => DB::table('banners as b')
                    ->where('b.state', 1)
                    ->whereNotExists(fn ($q) => $q->from('media_files as m')
                        ->whereColumn('m.entity_id', 'b.id')
                        ->where('m.entity_type', 'banners'))
                    ->count(),
                'detalle' => fn ($n) => "{$n} pieza(s) están encendidas y no se muestran porque les falta la imagen.",
            ],
            [
                'clave'       => 'cupones_por_vencer',
                'responsable' => 'marketing',
                'modulo'  => 'marketing.cupones',
                'titulo'  => 'Cupones y destaques por vencer',
                'urgente' => false,
                'ruta'    => '/marketing/cupones',
                'contar'  => function () use ($hoy) {
                    $limite = $hoy->copy()->addDays(3)->toDateString();

                    return DB::table('coupons')
                        ->where('state', 1)
                        ->whereDate('ends_at', '>=', $hoy)
                        ->whereDate('ends_at', '<=', $limite)
                        ->count()
                        + DB::table('featured_businesses')
                            ->where('state', 1)
                            ->whereDate('ends_at', '>=', $hoy)
                            ->whereDate('ends_at', '<=', $limite)
                            ->count();
                },
                'detalle' => fn ($n) => "{$n} vencen en los próximos tres días. Renovarlos antes evita el hueco.",
            ],
        ];
    }
}
