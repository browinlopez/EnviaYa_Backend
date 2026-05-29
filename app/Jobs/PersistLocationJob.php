<?php

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;

class PersistLocationJob implements ShouldQueue
{
    use Queueable;

    public $domiciliaryId;
    public $latitude;
    public $longitude;

    /** Alta frecuencia GPS: reintentar rápido, pocas veces */
    public int $tries   = 3;
    public int $timeout = 15;
    public int $backoff = 2;

    /**
     * Create a new job instance.
     */
    public function __construct($domiciliaryId, $latitude, $longitude)
    {
        $this->domiciliaryId = $domiciliaryId;
        $this->latitude      = $latitude;
        $this->longitude     = $longitude;
        // Cola de alta prioridad en RabbitMQ para tracking en tiempo real
        $this->onConnection('rabbitmq')->onQueue('gps_tracking');
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        // 1. Registrar el historial en order_geolocations
        DB::table('order_geolocations')->insert([
            'domiciliary_id' => $this->domiciliaryId,
            // Construimos el point desde lat/lng
            'location' => DB::raw("ST_MakePoint({$this->longitude}, {$this->latitude})"),
            'state' => 1
        ]);

        // 2. Actualizar la última posición del domiciliario
        DB::table('domiciliaries')
            ->where('id', $this->domiciliaryId)
            ->update([
                'last_location' => DB::raw("ST_MakePoint({$this->longitude}, {$this->latitude})")
            ]);
    }
}
