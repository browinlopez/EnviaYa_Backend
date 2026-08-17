<?php

namespace App\Services;

use App\Models\Invoice\Invoice;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * EMITE EL COMPROBANTE DE UN PEDIDO ENTREGADO
 *
 * NO es una factura electrónica ante la DIAN: es el comprobante interno de la
 * operación, para que cada pedido entregado deje un registro con número, fecha
 * y desglose que se pueda consultar y descargar. Cuando llegue la conexión con
 * la DIAN, esto es la base — le faltará el CUFE, la resolución y el XML.
 *
 * Tres decisiones:
 *
 * 1. Se emite al ENTREGAR, no al pagar. Un pedido en camino todavía puede
 *    cancelarse, y un comprobante de algo que no ocurrió obliga a anularlo.
 *    "Entregado" es el único momento en que la operación está cerrada.
 *
 * 2. Guarda una FOTO de los datos, no referencias. Si el negocio cambia de
 *    nombre o el producto de precio, la factura de marzo sigue diciendo lo de
 *    marzo. Armarla con joins contra las tablas vivas dejaría que un cambio de
 *    hoy reescribiera el pasado.
 *
 * 3. El PDF se genera al pedirlo, no se archiva. Los datos ya son inmutables,
 *    así que el documento es reproducible: guardarlo costaría espacio y añadiría
 *    un modo de fallo —factura que existe con PDF que no subió—. Con la DIAN
 *    esto cambia: ahí el documento firmado sí hay que conservarlo tal cual.
 */
class FacturaService
{
    /** Cuántas veces reintentar si dos entregas simultáneas piden el mismo número. */
    private const INTENTOS = 5;

    public const EMITIDA = 1;
    public const ANULADA = 0;

    /**
     * Emite el comprobante de un pedido, si le corresponde.
     *
     * Es IDEMPOTENTE: llamarlo dos veces sobre el mismo pedido devuelve la
     * factura que ya existe. Hace falta porque el disparador vive en el cambio
     * de estado, y un reintento del cliente o un doble clic no pueden producir
     * dos comprobantes del mismo pedido.
     *
     * @return Invoice|null null si el pedido no está entregado
     */
    public function emitirPara(int $pedidoId): ?Invoice
    {
        $existente = Invoice::where('orderSales_id', $pedidoId)->first();

        if ($existente) {
            return $existente;
        }

        $pedido = DB::table('orderssales as o')
            ->leftJoin('business as b', 'b.busines_id', '=', 'o.busines_id')
            ->leftJoin('buyer as by', 'by.buyer_id', '=', 'o.buyer_id')
            ->leftJoin('user as bu', 'bu.user_id', '=', 'by.user_id')
            ->leftJoin('user_address as ua', 'ua.address_id', '=', 'o.address_id')
            ->leftJoin('domiciliary as d', 'd.domiciliary_id', '=', 'o.domiciliary_id')
            ->leftJoin('user as du', 'du.user_id', '=', 'd.user_id')
            ->leftJoin('payment_methods as pm', 'pm.methods_id', '=', 'o.methods_id')
            ->where('o.orderSales_id', $pedidoId)
            ->first([
                'o.orderSales_id', 'o.busines_id', 'o.buyer_id', 'o.state',
                'o.subtotal', 'o.domicilio', 'o.discount', 'o.domiciliary_fee',
                'o.total', 'o.currency', 'o.sale_date', 'o.delivery_date',
                'b.name as negocio', 'b.NIT as negocio_nit',
                'b.razonSocial_DCD as negocio_razon', 'b.address as negocio_direccion',
                'b.phone as negocio_telefono',
                'bu.name as comprador', 'bu.phone as comprador_telefono',
                'ua.address as entrega',
                'du.name as domiciliario',
                'pm.name as medio_pago',
            ]);

        // Solo lo entregado. Un pedido en camino puede cancelarse todavía.
        if (!$pedido || (int) $pedido->state !== 4) {
            return null;
        }

        $renglones = DB::table('orderssales_detail as od')
            ->leftJoin('products as p', 'p.products_id', '=', 'od.product_id')
            ->where('od.orderSales_id', $pedidoId)
            ->get([
                'od.product_id', 'od.amount', 'od.unit_price',
                'p.name as producto',
            ]);

        $pago = DB::table('payments')
            ->where('orderSales_id', $pedidoId)
            ->whereIn('status', ['approved', 'paid'])
            ->orderByDesc('payments_id')
            ->first(['payments_id', 'provider', 'provider_payment_id']);

        $fecha = $pedido->delivery_date
            ? Carbon::parse($pedido->delivery_date)
            : Carbon::parse($pedido->sale_date ?? now());

        return $this->insertarConConsecutivo($fecha, [
            'orderSales_id'     => $pedidoId,
            'busines_id'        => $pedido->busines_id,
            'buyer_id'          => $pedido->buyer_id,
            'payments_id'       => $pago->payments_id ?? null,
            'payment_provider'  => $pago->provider ?? null,
            'payment_reference' => $pago->provider_payment_id ?? null,
            'invoice_date'      => $fecha,
            'subtotal'          => round((float) $pedido->subtotal, 2),
            'descuento'         => round((float) $pedido->discount, 2),
            'domicilio'         => round((float) $pedido->domicilio, 2),
            'domiciliary_fee'   => round((float) $pedido->domiciliary_fee, 2),
            /*
             * IVA en cero, a propósito y no por olvido.
             *
             * Los precios del catálogo se capturan con el impuesto incluido y no
             * hay tarifa por producto en la base. Desglosar un IVA inventado
             * —del 19 % sobre todo, por ejemplo— produciría un comprobante que
             * parece fiscal y que está mal: los alimentos no tributan igual que
             * un repuesto. Se deja en cero hasta que el catálogo diga la tarifa
             * de cada producto, que es lo que hará falta igualmente para la DIAN.
             */
            'iva'      => 0,
            'total'    => round((float) $pedido->total, 2),
            'currency' => $pedido->currency ?: 'COP',
            'state'    => self::EMITIDA,
            'snapshot' => [
                'negocio' => [
                    'nombre'       => $pedido->negocio,
                    'nit'          => $pedido->negocio_nit,
                    'razon_social' => $pedido->negocio_razon,
                    'direccion'    => $pedido->negocio_direccion,
                    'telefono'     => $pedido->negocio_telefono,
                ],
                'comprador' => [
                    'nombre'   => $pedido->comprador,
                    'telefono' => $pedido->comprador_telefono,
                    'entrega'  => $pedido->entrega,
                ],
                'entrega' => [
                    'domiciliario' => $pedido->domiciliario,
                    'fecha'        => $fecha->toDateTimeString(),
                    'medio_pago'   => $pedido->medio_pago,
                ],
                'renglones' => $renglones->map(fn ($r) => [
                    'producto'    => $r->producto ?? "Producto #{$r->product_id}",
                    'cantidad'    => (int) $r->amount,
                    'precio_unit' => round((float) $r->unit_price, 2),
                    'importe'     => round((float) $r->unit_price * (int) $r->amount, 2),
                ])->all(),
            ],
        ]);
    }

    /**
     * Inserta reservando el consecutivo.
     *
     * El número se calcula como "el último del año más uno" DENTRO de una
     * transacción, y la red de seguridad es el índice único: si dos entregas
     * simultáneas calculan el mismo, la segunda choca y se reintenta. Reservar
     * con un bloqueo de tabla sería más estricto y serializaría cada entrega de
     * la plataforma; dos facturas con el mismo número, en cambio, es un problema
     * que nadie descubre hasta la auditoría — de ahí que el que se elige sea el
     * que falla ruidosamente.
     */
    private function insertarConConsecutivo(Carbon $fecha, array $datos): Invoice
    {
        $anio = $fecha->year;

        for ($intento = 1; $intento <= self::INTENTOS; $intento++) {
            try {
                return DB::transaction(function () use ($anio, $datos) {
                    $datos['invoice_number'] = $this->siguienteNumero($anio);

                    return Invoice::create($datos);
                });
            } catch (QueryException $e) {
                // 23000 = violación de restricción. Puede ser el número
                // repetido (se reintenta) o el pedido repetido (ya hay factura).
                if ($e->getCode() !== '23000' || $intento === self::INTENTOS) {
                    throw $e;
                }

                $yaEsta = Invoice::where('orderSales_id', $datos['orderSales_id'])->first();

                if ($yaEsta) {
                    return $yaEsta;
                }

                // Otra entrega se llevó el número: se recalcula y se repite.
                usleep(random_int(10_000, 60_000));
            }
        }

        throw new RuntimeException('No se pudo reservar un número de factura.');
    }

    /** `FV-2026-000123`. El año va en el número para que el consecutivo reinicie. */
    private function siguienteNumero(int $anio): string
    {
        $prefijo = "FV-{$anio}-";

        $ultimo = DB::table('invoices')
            ->where('invoice_number', 'like', $prefijo . '%')
            ->orderByDesc('invoice_number')
            ->value('invoice_number');

        $consecutivo = $ultimo
            ? ((int) substr($ultimo, strlen($prefijo))) + 1
            : 1;

        return $prefijo . str_pad((string) $consecutivo, 6, '0', STR_PAD_LEFT);
    }

    /**
     * Anula una factura emitida por error.
     *
     * No se borra: un hueco en el consecutivo es exactamente lo que un
     * comprobante no puede tener. Queda con su número, marcada, con el motivo y
     * con quién lo hizo.
     */
    public function anular(Invoice $factura, string $motivo, ?int $usuarioId): Invoice
    {
        if ((int) $factura->state === self::ANULADA) {
            throw new RuntimeException('Esta factura ya está anulada.');
        }

        $factura->forceFill([
            'state'       => self::ANULADA,
            'void_reason' => trim($motivo),
            'voided_at'   => now(),
            'voided_by'   => $usuarioId,
        ])->save();

        return $factura->fresh();
    }

    /** El PDF del comprobante, armado con la foto guardada. */
    public function pdf(Invoice $factura): string
    {
        $html = view('documentos.factura', [
            'f'        => $factura,
            'snapshot' => $factura->snapshot ?? [],
            'empresa'  => [
                'nombre'     => config('services.contrato.empresa'),
                'nit'        => config('services.contrato.nit'),
                'plataforma' => config('services.contrato.plataforma'),
            ],
        ])->render();

        $opciones = new Options();
        $opciones->set('defaultFont', 'DejaVu Sans');
        // Nada externo: el documento tiene que salir igual sin red.
        $opciones->set('isRemoteEnabled', false);
        $opciones->set('isHtml5ParserEnabled', true);

        $dompdf = new Dompdf($opciones);
        $dompdf->loadHtml($html, 'UTF-8');
        // Media carta: es un comprobante de entrega, no un contrato.
        $dompdf->setPaper([0, 0, 396, 612], 'portrait');
        $dompdf->render();

        return $dompdf->output();
    }

    public function nombreArchivo(Invoice $factura): string
    {
        return 'comprobante-' . str_replace(['/', ' '], '-', $factura->invoice_number) . '.pdf';
    }
}
