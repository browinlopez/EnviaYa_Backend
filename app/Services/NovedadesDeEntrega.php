<?php

declare(strict_types=1);

namespace App\Services;

use App\Events\OrderStatusUpdated;
use App\Models\Order\OrdersSales;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * LO QUE LE PASA AL DOMICILIARIO EN LA CALLE.
 *
 * Un pedido en camino sólo podía acabar entregado. Si el cliente no abría, si
 * la dirección no existía o si el domiciliario se accidentaba, el pedido se
 * quedaba «en camino» para siempre y nadie sabía qué hacer con él. Esto le da
 * dos salidas más:
 *
 *  1. SOLTAR: me lo asignaron y no lo puedo llevar --se me varó la moto antes
 *     de recogerlo--. El pedido vuelve a «Para tomar» y el negocio se entera
 *     para dárselo a otro. Es lo que quedó en lugar de las ofertas con
 *     cuenta atrás: cubre «me lo dieron y no puedo» sin cambiar cómo los
 *     negocios reparten.
 *
 *  2. NOVEDAD: fui y no se pudo entregar. Queda escrito qué pasó, dónde y
 *     cuándo; el pedido pasa a DEVOLVIENDO (6) y el domiciliario lleva el
 *     producto y la plata de vuelta. Cuando el negocio confirma que los
 *     recibió, el pedido se cancela por el camino de siempre
 *     (`CancelacionDelPedido`: devuelve inventario, promoción, cupón,
 *     crédito y el pago en línea si lo hubo).
 *
 * EL ACCIDENTE ES UNA NOVEDAD MÁS, pero no la única cosa que hay que hacer: la
 * app ofrece además el SOS, que ya existe y registra el incidente de
 * seguridad. Aquí sólo se resuelve qué pasa con el pedido.
 */
class NovedadesDeEntrega
{
    /** Por qué no se pudo entregar. La clave viaja; el texto es para leer. */
    public const MOTIVOS = [
        'no_contesta' => 'El cliente no contesta',
        'direccion_incorrecta' => 'La dirección no existe o está mal',
        'cliente_cancelo' => 'El cliente ya no lo quiere',
        'producto_danado' => 'El producto se dañó',
        'accidente' => 'Tuve un accidente o falla de la moto',
        'otro' => 'Otra cosa',
    ];

    /** Por qué lo suelta antes de recogerlo. */
    public const MOTIVOS_PARA_SOLTAR = [
        'lejos' => 'Me queda muy lejos',
        'muchos' => 'Ya llevo muchos pedidos',
        'moto' => 'Problema con la moto',
        'otro' => 'Otra cosa',
    ];

    /** Los motivos en los que la nota es obligatoria: «otra cosa» no dice nada. */
    public const CON_NOTA = ['otro'];

    public function __construct(private readonly CancelacionDelPedido $cancelacion) {}

    /**
     * Soltar un pedido en camino: vuelve a «Para tomar».
     *
     * Sólo el domiciliario asignado, y sólo mientras el pedido siga en camino.
     */
    public function soltar(OrdersSales $pedido, int $domiciliarioId, int $userId, string $motivo, ?string $nota = null): OrdersSales
    {
        $this->validarMotivo($motivo, self::MOTIVOS_PARA_SOLTAR, $nota);

        $hecho = DB::transaction(function () use ($pedido, $domiciliarioId, $userId, $motivo, $nota) {
            $fresco = OrdersSales::where('orderSales_id', $pedido->orderSales_id)->lockForUpdate()->first();

            if (! $fresco || (int) $fresco->domiciliary_id !== $domiciliarioId) {
                throw new RuntimeException('Ese pedido no es tuyo.');
            }
            if ((int) $fresco->state !== OrdersSales::EN_CAMINO) {
                throw new RuntimeException('Ese pedido ya no está en camino.');
            }

            $fresco->state = OrdersSales::PREPARANDO;
            $fresco->domiciliary_id = null;
            $fresco->dispatched_at = null;
            // El PIN era para ESTE viaje: el siguiente domiciliario recibe otro.
            $fresco->pin_entrega = null;
            $fresco->pin_intentos = 0;
            $fresco->llego_at = null;
            $fresco->save();

            $this->historial($fresco, OrdersSales::PREPARANDO, 'Soltado: '.self::MOTIVOS_PARA_SOLTAR[$motivo].($nota ? " — {$nota}" : ''), $userId);

            return $fresco;
        });

        $hecho->loadMissing('business.owners');
        foreach ($hecho->business->owners as $duenio) {
            if ($duenio->user_id) {
                Avisos::para(
                    $duenio->user_id,
                    'pedido_soltado',
                    "El domiciliario soltó el pedido #{$hecho->orderSales_id}: ".mb_strtolower(self::MOTIVOS_PARA_SOLTAR[$motivo]).'. Dáselo a otro.',
                    ['order_id' => $hecho->orderSales_id],
                );
            }
        }

        // Vuelve a estar para tomar: los demás domiciliarios se enteran.
        AvisoDePedidoParaTomar::anunciar($hecho, excepto: $userId);
        $this->anunciar($hecho);

        return $hecho;
    }

    /**
     * Reportar que no se pudo entregar: el pedido pasa a DEVOLVIENDO.
     */
    public function reportar(
        OrdersSales $pedido,
        int $domiciliarioId,
        int $userId,
        string $motivo,
        ?string $nota = null,
        ?float $latitud = null,
        ?float $longitud = null,
    ): array {
        $this->validarMotivo($motivo, self::MOTIVOS, $nota);

        [$fresco, $novedadId] = DB::transaction(function () use ($pedido, $domiciliarioId, $userId, $motivo, $nota, $latitud, $longitud) {
            $fresco = OrdersSales::where('orderSales_id', $pedido->orderSales_id)->lockForUpdate()->first();

            if (! $fresco || (int) $fresco->domiciliary_id !== $domiciliarioId) {
                throw new RuntimeException('Ese pedido no es tuyo.');
            }
            if ((int) $fresco->state !== OrdersSales::EN_CAMINO) {
                throw new RuntimeException('Ese pedido ya no está en camino.');
            }

            $fresco->state = OrdersSales::DEVOLVIENDO;
            $fresco->save();

            $novedadId = DB::table('novedades_de_entrega')->insertGetId([
                'order_id' => $fresco->orderSales_id,
                'domiciliary_id' => $domiciliarioId,
                'motivo' => $motivo,
                'nota' => $nota,
                'latitude' => $latitud,
                'longitude' => $longitud,
                // El viaje se hizo aunque no hubo entrega. Ver la migración.
                'domicilio_reconocido' => (float) ($fresco->domiciliary_fee ?? 0),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $this->historial($fresco, OrdersSales::DEVOLVIENDO, self::MOTIVOS[$motivo].($nota ? " — {$nota}" : ''), $userId);

            return [$fresco, $novedadId];
        });

        $texto = mb_strtolower(self::MOTIVOS[$motivo]);

        $fresco->loadMissing('business.owners', 'buyer');
        foreach ($fresco->business->owners as $duenio) {
            if ($duenio->user_id) {
                Avisos::para(
                    $duenio->user_id,
                    'pedido_devolviendo',
                    "No se pudo entregar el pedido #{$fresco->orderSales_id} ({$texto}). El domiciliario lo trae de vuelta.",
                    ['order_id' => $fresco->orderSales_id],
                );
            }
        }
        if ($fresco->buyer?->user_id) {
            Avisos::para(
                $fresco->buyer->user_id,
                'pedido_no_entregado',
                "No pudimos entregar tu pedido #{$fresco->orderSales_id}. El negocio se va a comunicar contigo.",
                ['order_id' => $fresco->orderSales_id],
            );
        }

        $this->anunciar($fresco);

        return ['pedido' => $fresco, 'novedad_id' => $novedadId];
    }

    /**
     * El negocio confirma que le devolvieron el producto (y la plata): el
     * pedido se cancela por el camino de siempre.
     */
    public function recibirDevolucion(OrdersSales $pedido, int $userId): OrdersSales
    {
        if ((int) $pedido->state !== OrdersSales::DEVOLVIENDO) {
            throw new RuntimeException('Ese pedido no está en devolución.');
        }

        $novedad = DB::table('novedades_de_entrega')
            ->where('order_id', $pedido->orderSales_id)
            ->orderByDesc('id')
            ->first();

        $this->cancelacion->cancelar(
            $pedido,
            $userId,
            CancelacionDelPedido::TIENDA,
            'No entregado: '.(self::MOTIVOS[$novedad->motivo ?? 'otro'] ?? 'novedad en la entrega'),
        );

        if ($novedad) {
            DB::table('novedades_de_entrega')->where('id', $novedad->id)->update([
                'devolucion_recibida_en' => now(),
                'recibida_por' => $userId,
                'updated_at' => now(),
            ]);
        }

        return $pedido->refresh();
    }

    private function validarMotivo(string $motivo, array $lista, ?string $nota): void
    {
        if (! array_key_exists($motivo, $lista)) {
            throw new RuntimeException('Ese motivo no existe.');
        }
        if (in_array($motivo, self::CON_NOTA, true) && trim((string) $nota) === '') {
            throw new RuntimeException('Cuéntanos qué pasó.');
        }
    }

    private function historial(OrdersSales $pedido, int $estado, string $motivo, int $userId): void
    {
        DB::table('status_history')->insert([
            'order_id' => $pedido->orderSales_id,
            'status_history' => $estado,
            'motivo' => mb_substr($motivo, 0, 250),
            'state' => 1,
            'created_by' => $userId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function anunciar(OrdersSales $pedido): void
    {
        try {
            broadcast(new OrderStatusUpdated($pedido));
        } catch (\Throwable $e) {
            Log::warning('No se pudo anunciar el cambio del pedido', [
                'order_id' => $pedido->orderSales_id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
