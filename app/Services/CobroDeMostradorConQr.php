<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\FalloDeLaPasarela;
use App\Models\Negocio\VentaDeMostrador;
use App\Support\UnidadDeVenta;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * EL QR BRE-B DEL MOSTRADOR, por Bold.
 *
 * Es el mismo cobro que el QR de los pedidos --intención, pago con
 * `qr_format = BOLD_BASE64`, aviso de Bold o consulta del estado--, pero
 * atado a una VENTA DE MOSTRADOR y no a un pedido: no hay comprador, ni
 * dirección, ni domiciliario. Por eso vive aparte y no se mete en
 * `PaymentController`, que está cosido a `orderssales` en cada paso.
 *
 * La referencia empieza por `MOS-`: así el aviso de Bold sabe, sin buscar en
 * dos sitios, que es de una venta de mostrador.
 */
class CobroDeMostradorConQr
{
    public const PREFIJO = 'MOS-';

    public function __construct(private readonly BoldService $bold) {}

    public static function esDeMostrador(?string $referencia): bool
    {
        return $referencia !== null && str_starts_with($referencia, self::PREFIJO);
    }

    /**
     * Pide el QR a Bold y lo guarda en la venta. Si Bold falla, lanza
     * `FalloDeLaPasarela` y quien llama deshace la venta.
     */
    public function cobrar(VentaDeMostrador $venta): VentaDeMostrador
    {
        $negocio = DB::table('business')->where('busines_id', $venta->busines_id)->first();
        $dueno = DB::table('owner_busines as ob')
            ->join('owner as o', 'o.owner_id', '=', 'ob.owner_id')
            ->join('user as u', 'u.user_id', '=', 'o.user_id')
            ->where('ob.busines_id', $venta->busines_id)
            ->first(['u.name', 'u.email', 'u.phone']);

        $referencia = self::PREFIJO.$venta->venta_id.'-'.Str::upper(Str::random(8));
        $total = (int) round((float) $venta->total);
        $telefono = $dueno->phone ?? '3000000000';
        $direccion = [
            'street1' => $negocio->address ?? 'Calle 1',
            'street2' => '',
            'city' => 'Barranquilla',
            'zip_code' => '080001',
            'province' => 'Atlántico',
            'country' => 'CO',
            'phone' => $telefono,
        ];

        $this->bold->createIntent([
            'reference_id' => $referencia,
            'amount' => ['currency' => 'COP', 'total_amount' => $total],
            'description' => 'Compra en '.($negocio->name ?? 'el negocio'),
            'callback_url' => rtrim((string) config('services.sitio.url'), '/')
                .'/pago/volver?ref='.$referencia,
            'customer' => [
                'name' => $negocio->name ?? 'Cliente del mostrador',
                'phone' => $telefono,
                'email' => $dueno->email ?? 'correo@ejemplo.com',
                'billing_address' => $direccion,
            ],
        ]);

        /*
         * EL PAGADOR ES LA TIENDA. En el mostrador no sabemos quién escanea
         * --no tiene cuenta, no dio datos-- y Bold exige un pagador completo.
         * El que paga de verdad lo pone su banco al escanear.
         */
        $pago = $this->bold->makePayment([
            'reference_id' => $referencia,
            'payer' => [
                'person_type' => 'NATURAL_PERSON',
                'name' => $negocio->name ?? 'Cliente del mostrador',
                'phone' => $telefono,
                'email' => $dueno->email ?? 'correo@ejemplo.com',
                'document_type' => 'CEDULA',
                'document_number' => '1234567890',
                'billing_address' => $direccion,
            ],
            'payment_method' => ['name' => 'QR', 'qr_format' => 'BOLD_BASE64'],
            'amount' => ['currency' => 'COP', 'total_amount' => $total],
            // Bold exige entre 2 y 60 caracteres: el número solo («6») no pasa.
            'metadata' => ['key' => 'venta_mostrador', 'value' => $referencia],
            'device_fingerprint' => [
                'device_type' => 'MOBILE', 'os' => 'Android', 'model' => 'ReactNative',
                'browser' => 'MobileApp', 'java_enabled' => false, 'language' => 'es',
                'color_depth' => 24, 'screen_height' => 800, 'screen_width' => 400,
                'time_zone_offset' => -300,
            ],
        ]);

        $siguiente = $pago['next_actions'] ?? [];
        $qr = $siguiente['qr_payload'] ?? ($siguiente['qr']['payload'] ?? null);

        if (! $qr) {
            throw new RuntimeException('Bold no devolvió el código QR.');
        }

        $venta->update([
            'estado_pago' => 'esperando',
            'bold_referencia' => $referencia,
            'bold_transaccion' => $pago['transaction_id'] ?? null,
            'qr_payload' => $qr,
            'qr_expira_at' => $this->caducidad($siguiente),
        ]);

        return $venta->fresh();
    }

    /**
     * Pregunta a Bold cómo va. Para cuando el aviso no llega --en local
     * nunca llega-- o tarda: la pantalla consulta cada pocos segundos.
     */
    public function consultar(VentaDeMostrador $venta): VentaDeMostrador
    {
        if ($venta->estado_pago !== 'esperando' || ! $venta->bold_referencia) {
            return $venta;
        }

        try {
            $estado = strtoupper((string) ($this->bold->checkPayment($venta->bold_referencia)['status'] ?? ''));
        } catch (FalloDeLaPasarela $e) {
            Log::warning('Bold no pudo informar del pago del mostrador', [
                'venta' => $venta->venta_id,
                'codigo' => $e->codigo(),
            ]);

            return $venta;
        }

        if ($estado === 'APPROVED') {
            $this->confirmar($venta);
        } elseif (in_array($estado, ['REJECTED', 'FAILED', 'VOIDED', 'EXPIRED'], true)) {
            $this->rechazar($venta);
        }

        return $venta->fresh();
    }

    /**
     * Pagada. El cambio de estado es una sola sentencia condicionada: el
     * aviso de Bold y la consulta de la pantalla pueden llegar a la vez y
     * solo uno debe ganar.
     */
    public function confirmar(VentaDeMostrador $venta): bool
    {
        $comision = round((float) $venta->total * (float) config('operacion.comision_qr', 0.0289), 2);

        return DB::table('ventas_mostrador')
            ->where('venta_id', $venta->venta_id)
            ->where('estado_pago', 'esperando')
            ->update([
                'estado_pago' => 'pagada',
                'comision_pasarela' => $comision,
                'vendida_at' => now(),
                'updated_at' => now(),
            ]) === 1;
    }

    /** No se pagó: la venta no cuenta y el inventario vuelve. */
    public function rechazar(VentaDeMostrador $venta, string $estado = 'rechazada'): bool
    {
        return DB::transaction(function () use ($venta, $estado) {
            $cambio = DB::table('ventas_mostrador')
                ->where('venta_id', $venta->venta_id)
                ->where('estado_pago', 'esperando')
                ->update(['estado_pago' => $estado, 'updated_at' => now()]) === 1;

            if ($cambio) {
                foreach ($venta->lineas()->get() as $l) {
                    $q = DB::table('products_business')
                        ->where('busines_id', $venta->busines_id)
                        ->where('products_id', $l->products_id);

                    UnidadDeVenta::esPeso($l->sale_unit)
                        ? $q->increment('stock_weight', (float) $l->cantidad)
                        : $q->increment('amount', (int) $l->cantidad);
                }
            }

            return $cambio;
        });
    }

    /** `expires_at` llega en nanosegundos; `expires_in`, en segundos. */
    private function caducidad(array $siguiente): Carbon
    {
        $crudo = $siguiente['expires_at'] ?? null;
        if (is_numeric($crudo)) {
            foreach ([1_000_000_000, 1_000_000, 1_000, 1] as $divisor) {
                $segundos = intdiv((int) $crudo, $divisor);
                if ($segundos > 1_600_000_000 && $segundos < 4_000_000_000) {
                    return Carbon::createFromTimestamp($segundos, config('app.timezone'));
                }
            }
        }
        $segundos = $siguiente['expires_in'] ?? ($siguiente['qr']['expires_in'] ?? null);

        return $segundos ? now()->addSeconds((int) $segundos) : now()->addMinutes(10);
    }
}
