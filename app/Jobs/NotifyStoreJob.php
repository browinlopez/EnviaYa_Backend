<?php

namespace App\Jobs;

use App\Models\OrderSale;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class NotifyStoreJob implements ShouldQueue
{
    use Queueable;

    public $orderId;

    /** 3 reintentos con backoff exponencial para APIs externas lentas */
    public int $tries   = 3;
    public int $timeout = 30;

    public function backoff(): array
    {
        return [10, 30, 60]; // Esperas en segundos entre cada reintento
    }

    /**
     * Create a new job instance.
     */
    public function __construct($orderId)
    {
        $this->orderId = $orderId;
        // Cola de notificaciones en RabbitMQ
        $this->onConnection('rabbitmq')->onQueue('notifications');
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $order = OrderSale::with('business')->find($this->orderId);

        if (!$order) {
            return;
        }

        // Lógica para enviar Push Notification / SMS a la tienda
        // Si falla (ej: HTTP timeout del API de SMS), Laravel Queue lo reintentará basado en $tries
        Log::info("Notificando a la tienda " . $order->business_id . " sobre el pedido programado " . $order->id);
    }
}
