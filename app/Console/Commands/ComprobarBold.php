<?php

namespace App\Console\Commands;

use App\Models\Order\OrdersSales;
use App\Models\Payment\Payment;
use App\Models\Payment\PaymentIntent;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Dice si Bold está listo para cobrar, ANTES de que alguien pague de verdad.
 *
 * POR QUÉ EXISTE. Con pago en línea el pedido se guarda antes de cobrar y queda
 * ESCONDIDO hasta que llega el aviso de Bold. Si ese aviso se rechaza —y sin
 * `BOLD_WEBHOOK_SECRET` se rechaza TODO, a propósito, porque una firma HMAC sin
 * clave la falsifica cualquiera— el cliente paga y no tiene pedido, y la tienda
 * ni se entera de que hay algo que preparar.
 *
 * Eso no se descubre revisando código: se descubre cuando alguien reclama un
 * cobro. Con esto se comprueba en diez segundos:
 *
 *     php artisan bold:comprobar
 *
 * Y con `--simular` va más lejos: crea un pedido de prueba, le manda un aviso
 * FIRMADO como lo firmaría Bold, y comprueba que el pedido pasa a pagado.
 * Después lo borra. Es el ensayo completo sin mover un peso.
 */
class ComprobarBold extends Command
{
    protected $signature = 'bold:comprobar
                            {--simular : Ensayar el aviso completo con un pedido de prueba}';

    protected $description = 'Comprueba que Bold está configurado para cobrar y confirmar pedidos';

    public function handle(): int
    {
        $this->newLine();
        $this->line('  <options=bold>CONFIGURACIÓN DE BOLD</>');
        $this->newLine();

        $apiKey  = (string) config('services.bold.api_key');
        $secreto = (string) config('services.bold.webhook_secret');
        $baseUrl = (string) config('services.bold.base_url');

        $this->fila('BOLD_BASE_URL', $baseUrl !== '', $baseUrl ?: '(vacío)');
        $this->fila(
            'BOLD_API_KEY',
            $apiKey !== '',
            $apiKey !== '' ? 'puesta (' . strlen($apiKey) . ' caracteres)' : 'VACÍA',
        );
        $this->fila(
            'BOLD_WEBHOOK_SECRET',
            $secreto !== '',
            $secreto !== '' ? 'puesto (' . strlen($secreto) . ' caracteres)' : 'VACÍO',
        );

        $this->newLine();

        if ($secreto === '') {
            $this->error('  Sin BOLD_WEBHOOK_SECRET, TODO aviso de Bold se rechaza.');
            $this->line('  El cliente paga, Bold cobra, y el pedido nunca se marca como pagado.');
            $this->newLine();
            $this->line('  El valor está en el panel de Bold, en la configuración del webhook.');
            $this->line('  Se pone en el entorno del servidor y se reinicia el contenedor.');
            $this->newLine();

            return self::FAILURE;
        }

        if ($apiKey === '') {
            $this->error('  Sin BOLD_API_KEY no se puede abrir un cobro: no habría qué confirmar.');
            $this->newLine();

            return self::FAILURE;
        }

        $this->info('  La configuración está completa.');
        $this->newLine();

        if (!$this->option('simular')) {
            $this->line('  Para el ensayo completo:  <options=bold>php artisan bold:comprobar --simular</>');
            $this->newLine();

            return self::SUCCESS;
        }

        return $this->simular($secreto);
    }

    /**
     * El ensayo: un pedido de prueba, un aviso firmado, y a ver si se marca.
     *
     * Todo dentro de una transacción que SIEMPRE se deshace. El comando se
     * corre contra producción —es donde importa— y no puede dejar un pedido
     * fantasma en la lista de nadie.
     */
    private function simular(string $secreto): int
    {
        $this->line('  <options=bold>ENSAYO DEL AVISO</>');
        $this->newLine();

        $ok = false;

        DB::beginTransaction();

        try {
            $pedido = OrdersSales::query()->latest('orderSales_id')->first();

            if (!$pedido) {
                $this->error('  No hay ningún pedido en la base con el que ensayar.');
                DB::rollBack();

                return self::FAILURE;
            }

            $referencia = 'ensayo-' . uniqid();

            PaymentIntent::create([
                'orderSales_id'     => $pedido->orderSales_id,
                'provider'          => 'bold',
                'bold_reference_id' => $referencia,
                'amount'            => 1000,
                'currency'          => 'COP',
                'status'            => 'pending',
            ]);

            Payment::create([
                'orderSales_id'  => $pedido->orderSales_id,
                'provider'       => 'bold',
                'amount'         => 1000,
                'total'          => 1000,
                'payment_status' => 0,
                'status'         => 'pending',
            ]);

            $cuerpo = json_encode([
                'id'      => 'ensayo-' . uniqid(),
                'type'    => 'SALE_APPROVED',
                'subject' => 'ensayo-tx',
                'data'    => ['metadata' => ['reference' => $referencia]],
            ]);

            // Se firma igual que Bold: base64 del cuerpo, HMAC-SHA256.
            $firma = hash_hmac('sha256', base64_encode($cuerpo), $secreto);

            $respuesta = app()->handle(
                \Illuminate\Http\Request::create(
                    '/v1/webhooks/bold',
                    'POST',
                    [],
                    [],
                    [],
                    [
                        'CONTENT_TYPE'          => 'application/json',
                        'HTTP_ACCEPT'           => 'application/json',
                        'HTTP_X_BOLD_SIGNATURE' => $firma,
                    ],
                    $cuerpo,
                ),
            );

            $estado = $respuesta->getStatusCode();
            $marcado = OrdersSales::find($pedido->orderSales_id)->payment_state === 'paid';

            $this->fila('El webhook acepta la firma', $estado === 200, "HTTP {$estado}");
            $this->fila('El pedido queda pagado', $marcado, $marcado ? 'sí' : 'NO');

            $ok = $estado === 200 && $marcado;
        } catch (\Throwable $e) {
            $this->error('  El ensayo reventó: ' . $e->getMessage());
        } finally {
            // Siempre. El ensayo no deja rastro.
            DB::rollBack();
        }

        $this->newLine();

        if ($ok) {
            $this->info('  Bold está listo. Un cobro aprobado marcará el pedido.');
            $this->newLine();

            return self::SUCCESS;
        }

        $this->error('  El aviso no marcó el pedido. Revisa el secreto y el registro.');
        $this->newLine();

        return self::FAILURE;
    }

    private function fila(string $que, bool $bien, string $detalle): void
    {
        $marca = $bien ? '<fg=green>OK   </>' : '<fg=red>FALTA</>';

        $this->line("  {$marca} " . str_pad($que, 30) . " {$detalle}");
    }
}
