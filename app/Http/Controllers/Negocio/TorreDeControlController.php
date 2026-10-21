<?php

namespace App\Http\Controllers\Negocio;

use App\Http\Controllers\Controller;
use App\Models\Business;
use App\Models\Order\OrderGeolocation;
use App\Models\Order\OrdersSales;
use App\Services\Ajustes;
use App\Services\CustodiaDeEfectivo;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * LA TORRE DE CONTROL DE LA FLOTA
 *
 * Una sola llamada que responde las tres preguntas que el tendero se hace
 * mirando la calle: quién está repartiendo, qué lleva encima cada uno y cuánta
 * plata carga.
 *
 * POR QUÉ UNA SOLA Y NO LAS QUE YA HAY. Los datos existen repartidos —la flota
 * en `/negocio/domiciliarios`, la posición en `/orders/geolocation/latest`, los
 * pedidos en `/negocio/pedidos`—, pero la torre se refresca sola cada pocos
 * segundos: con seis domiciliarios serían ocho llamadas por vuelta, y el
 * servidor acabaría atendiendo la misma pantalla seis veces.
 *
 * LA POSICIÓN VIAJA CON SU EDAD, SIEMPRE.
 *
 * Es lo más importante de este archivo. Hoy el teléfono solo reporta mientras
 * el domiciliario tiene abierta la pantalla de seguimiento de un pedido y la
 * app en primer plano: uno libre no reporta nada, y uno en camino deja de
 * reportar en cuanto bloquea el teléfono. Un pin quieto en el mapa NO significa
 * una moto quieta.
 *
 * Por eso no se devuelve la coordenada a secas sino `hace_minutos` al lado, y
 * la pantalla está obligada a enseñarlo. Una torre que pinta pines sin edad es
 * peor que no tener torre: el tendero llama a regañar a alguien que va
 * manejando.
 *
 * Mientras no exista el reporte en segundo plano —que obliga a compilar, a
 * pedir permisos nuevos y a decirlo en el acuerdo del domiciliario, ver
 * `Documentacion/06-Planes/PANEL-DEL-TENDERO.md`— esta es la verdad que se
 * puede contar.
 */
class TorreDeControlController extends Controller
{
    /**
     * Cuánto hacia atrás se busca la última posición.
     *
     * Media jornada. Más atrás la coordenada ya no dice dónde está nadie —dice
     * dónde estuvo ayer—, y enseñarla con «hace 19 horas» al lado solo llena la
     * pantalla de ruido. Fuera de la ventana se devuelve `null`, que la
     * pantalla cuenta como «sin posición».
     */
    private const HORAS_DE_POSICION = 12;

    /** El estado del pedido que significa «lo lleva encima». */
    private const EN_CAMINO = 3;

    public function __construct(private CustodiaDeEfectivo $custodia)
    {
    }

    public function index(Request $request)
    {
        /** @var Business $negocio */
        $negocio = $request->attributes->get('negocio');
        $negocio->loadMissing('domiciliaries.user');

        $flota = $negocio->domiciliaries;
        $ids   = $flota->pluck('domiciliary_id')->map(fn ($id) => (int) $id)->all();

        $pedidos   = $this->pedidosABordo((int) $negocio->busines_id, $ids);
        $posicion  = $this->ultimaPosicion($ids);
        $tope      = $negocio->max_courier_cash !== null ? (float) $negocio->max_courier_cash : null;
        $simultaneos = (int) Ajustes::valor('operacion.entregas_simultaneas');

        $torre = $flota->map(function ($d) use ($pedidos, $posicion, $tope) {
            $id       = (int) $d->domiciliary_id;
            $suyos    = $pedidos->get($id, collect());
            $efectivo = round($this->custodia->saldo($id), 2);

            return [
                'domiciliary_id' => $id,
                'user_id'        => (int) $d->user_id,
                'name'           => $d->user?->name,
                'phone'          => $d->user?->phone,
                'disponible'     => (bool) $d->available,

                'pedidos'        => $suyos->values(),
                'a_bordo'        => $suyos->count(),
                /*
                 * Desde cuándo lleva repartiendo: el despacho del pedido MÁS
                 * VIEJO que carga, no el último. Es la cifra que hace saltar
                 * al tendero —«lleva hora y media con ese pedido»—, y
                 * tomando el último se escondería justamente ese caso cada
                 * vez que se le despacha uno nuevo.
                 */
                'desde'          => $suyos->pluck('despachado_el')->filter()->sort()->first(),

                'efectivo'       => $efectivo,
                'tope_efectivo'  => $tope,
                'le_cabe'        => $tope === null ? null : max(0, round($tope - $efectivo, 2)),
                'sobre_el_tope'  => $tope !== null && $efectivo > $tope,

                'posicion'       => $posicion->get($id),
            ];
        });

        return response()->json([
            'busines_id'         => (int) $negocio->busines_id,
            'actualizado'        => now()->toIso8601String(),
            'tope_efectivo'      => $tope,
            'maximo_simultaneos' => $simultaneos,
            /*
             * La pantalla no tiene que saber cuántas horas atrás se buscó: se
             * le dice, y así el texto de «sin posición» puede ser concreto en
             * vez de un «no sabemos» que no explica nada.
             */
            'horas_de_posicion'  => self::HORAS_DE_POSICION,
            'flota'              => $torre->values(),
        ]);
    }

    /**
     * Lo que cada uno lleva encima, agrupado por domiciliario.
     *
     * Solo pedidos DE ESTA TIENDA: un domiciliario puede repartir para varias,
     * y lo que lleve de la tienda de al lado no es asunto de este tendero.
     * El efectivo sí es el total, porque el tope de la bolsa es uno solo —y es
     * lo que decide si se le puede despachar otro contra entrega—.
     *
     * @param  list<int>  $ids
     * @return Collection<int, Collection<int, array>>
     */
    private function pedidosABordo(int $businessId, array $ids): Collection
    {
        if ($ids === []) {
            return collect();
        }

        return OrdersSales::where('busines_id', $businessId)
            ->whereIn('domiciliary_id', $ids)
            ->where('state', self::EN_CAMINO)
            ->with(['buyer.user', 'address'])
            ->get()
            ->map(fn (OrdersSales $o) => [
                'domiciliary_id' => (int) $o->domiciliary_id,
                'orderSales_id'  => (int) $o->orderSales_id,
                'cliente'        => $o->buyer?->user?->name,
                'telefono'       => $o->buyer?->user?->phone,
                'direccion'      => $o->pickup
                    ? null
                    : (trim(implode(', ', array_filter([
                        $o->address?->street,
                        $o->address?->address,
                    ]))) ?: null),
                'recoge_en_tienda' => (bool) $o->pickup,
                'total'          => (float) $o->total,
                /*
                 * Si va contra entrega. Es lo que convierte un pedido en plata
                 * encima, y por eso el tendero necesita distinguirlo de un
                 * pedido ya pagado al mirar la fila.
                 */
                'en_efectivo'    => (int) $o->methods_id === 1,
                'despachado_el'  => optional($o->dispatched_at)->toIso8601String(),
                'minutos'        => $o->dispatched_at
                    ? (int) $o->dispatched_at->diffInMinutes(now())
                    : null,
            ])
            ->groupBy('domiciliary_id');
    }

    /**
     * La última posición conocida de cada uno, con su edad.
     *
     * Se trae en UNA consulta y se agrupa acá. Por domiciliario serían tantas
     * consultas como motos, y la torre se refresca sola: eso es lo que convierte
     * una pantalla en una tormenta contra la base de datos.
     *
     * @param  list<int>  $ids
     * @return Collection<int, array>
     */
    private function ultimaPosicion(array $ids): Collection
    {
        if ($ids === []) {
            return collect();
        }

        return OrderGeolocation::whereIn('domiciliary_id', $ids)
            ->where('created_at', '>=', now()->subHours(self::HORAS_DE_POSICION))
            ->orderByDesc('created_at')
            ->get()
            ->groupBy('domiciliary_id')
            ->map(function ($puntos) {
                $ultimo = $puntos->first();

                return [
                    'latitude'      => (float) $ultimo->latitude,
                    'longitude'     => (float) $ultimo->longitude,
                    'orderSales_id' => (int) $ultimo->orderSales_id,
                    'at'            => optional($ultimo->created_at)->toIso8601String(),
                    // La edad va calculada desde acá: el reloj del navegador
                    // del tendero puede estar corrido, y «hace 14 min» tiene
                    // que significar catorce minutos de verdad.
                    'hace_minutos'  => $ultimo->created_at
                        ? (int) $ultimo->created_at->diffInMinutes(now())
                        : null,
                ];
            });
    }
}
