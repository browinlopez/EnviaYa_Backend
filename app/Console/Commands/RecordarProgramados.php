<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\RecordatorioDeProgramados;
use Illuminate\Console\Command;

class RecordarProgramados extends Command
{
    protected $signature = 'pedidos:recordar-programados';

    protected $description = 'Recuerda al negocio los pedidos programados media hora antes de la hora pedida';

    public function handle(): int
    {
        $n = RecordatorioDeProgramados::recordar();
        $this->info("Recordados: {$n}");

        return self::SUCCESS;
    }
}
