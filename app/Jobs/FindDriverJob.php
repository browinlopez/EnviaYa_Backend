<?php

namespace App\Jobs;

use App\Models\OrderSale;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class FindDriverJob implements ShouldQueue
{
    use Queueable;

    public $orderId;
    public $radius;

    /** Número de reintentos antes de marcar como fallido */
    public int $tries = 5;

    /** Segundos antes de que el job sea devuelto a la cola si no responde */
    public int $timeout = 60;

    /** Segundos a esperar antes del siguiente reintento */
    public int $backoff = 10;

    /**
     * Create a new job instance.
     */
    public function __construct($orderId, $radius = 3000)
    {
        $this->orderId = $orderId;
        $this->radius  = $radius;
        // Forzar conexión RabbitMQ y cola dedicada
        $this->onConnection('rabbitmq')->onQueue('orders');
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $order = OrderSale::find($this->orderId);
        if (!$order || $order->domiciliary_id) {
            // Si la orden ya fue asignada o no existe, abortamos
            return;
        }

        // Suponiendo que pickup_location tiene un punto geográfico
        // Buscamos domiciliarios disponibles en el radio usando PostGIS nativo
        // $order->pickup_location debe ser transformado a lat/lng o usamos ST_X y ST_Y
        
        $drivers = DB::select("
            SELECT id, user_id, 
                   ST_Distance(last_location, (SELECT pickup_location FROM orders_sales WHERE id = :orderId)) AS distance_meters
            FROM domiciliaries
            WHERE available = 1
              AND last_location IS NOT NULL
              AND ST_DWithin(last_location, (SELECT pickup_location FROM orders_sales WHERE id = :orderId2), :radius)
            ORDER BY distance_meters ASC
            LIMIT 10
        ", [
            'orderId' => $order->id,
            'orderId2' => $order->id,
            'radius' => $this->radius
        ]);

        if (empty($drivers)) {
            Log::warning("No drivers found for order {$this->orderId} in radius {$this->radius}");
            
            // Reintentar expandiendo el radio en 1 minuto
            if ($this->radius < 10000) {
                FindDriverJob::dispatch($this->orderId, $this->radius + 2000)->delay(now()->addSeconds(30));
            } else {
                // Escalar a soporte humano o cancelar la orden
                Log::error("Driver search failed for order {$this->orderId}, max radius reached.");
            }
            return;
        }

        // Aquí enviaríamos la oferta a los drivers encontrados (ej: Push Notification al primero)
        $bestDriver = $drivers[0];
        Log::info("Best driver for order {$this->orderId} is driver {$bestDriver->id} at {$bestDriver->distance_meters} meters.");
        
        // Despachar evento para notificar al driver...
    }
}
