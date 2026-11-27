<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Services\Ajustes;
use Database\Seeders\DemostracionQa\Conjunto;
use Database\Seeders\DemostracionQa\Escenario;
use Database\Seeders\DemostracionQa\Guion;
use Database\Seeders\DemostracionQa\Mostrador;
use Database\Seeders\DemostracionQa\Negocios;
use Database\Seeders\DemostracionQa\Pedidos;
use Database\Seeders\DemostracionQa\Personas;
use Database\Seeders\DemostracionQa\Purga;
use Database\Seeders\DemostracionQa\Referencias;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use RuntimeException;

// Demo autocontenida para QA: crea solo lo suyo. DEMO_QA_PASSWORD siembra; DEMO_PURGAR=1 retira.
class DemostracionQaSeeder extends Seeder
{
    // Semilla fija: dos corridas el mismo día dan los mismos datos.
    private const SEMILLA = 20261127;

    public function run(): void
    {
        if (app()->isProduction() && ! config('semillas.permitir_en_produccion')) {
            $this->command?->warn('DemostracionQaSeeder no corre en producción: son datos falsos.');

            return;
        }

        $purgar = (bool) config('semillas.demo_qa_purgar');
        $clave = (string) config('semillas.demo_qa_clave');

        if (! $purgar && $clave === '') {
            throw new RuntimeException('Falta DEMO_QA_PASSWORD: es la clave común de las cuentas de demostración.');
        }

        mt_srand(self::SEMILLA);

        if ($purgar) {
            $this->informarPurga(DB::transaction(fn () => (new Purga)->correr()));

            return;
        }

        Referencias::comprobarCatalogos();

        $e = new Escenario(
            Hash::make($clave),
            (float) (Ajustes::valor('operacion.tarifa_domicilio') ?? 5000),
            (float) (Ajustes::valor('operacion.reparto_domiciliario') ?? 0.75),
            (float) (Ajustes::valor('operacion.comision_plataforma') ?? 0.03),
        );
        $e->municipios = Referencias::municipios();
        $e->tipos = Referencias::tipos();

        // La corrida anterior se va en la misma transacción: si algo falla, queda como estaba.
        $retirado = DB::transaction(function () use ($e) {
            $retirado = (new Purga)->correr();
            $conjunto = new Conjunto($e);
            $conjunto->edificio();
            (new Personas($e))->sembrar();
            $e->lote->guardar();
            (new Negocios($e))->sembrar();
            $e->lote->guardar();
            $pedidos = new Pedidos($e);
            $pedidos->sembrar();
            (new Mostrador($e))->sembrar();
            $conjunto->porteria();
            $e->lote->guardar();
            $pedidos->calificaciones();

            return $retirado;
        });

        $this->informar($e, $retirado);
    }

    /** @param array<string, int> $retirado */
    private function informar(Escenario $e, array $retirado): void
    {
        $negocios = [];

        foreach ($e->negocios as $n) {
            $negocios[$e->duenos[$n['dueno']]['correo']][] = $n['nombre'];
        }

        $filas = [];

        foreach ($negocios as $correo => $suyos) {
            $filas[] = ['Dueño (rol 2)', $correo, implode(', ', $suyos)];
        }

        foreach ($e->cuentas[3] ?? [] as $correo) {
            $filas[] = ['Domiciliario (rol 3)', $correo, ''];
        }

        $filas[] = ['Admin. conjunto (rol 5)', $e->cuentas[5][0], Guion::CONJUNTO['nombre']];
        $deTurno = (new Conjunto($e))->deTurno($e->ahora);

        foreach ($e->cuentas[6] as $k => $correo) {
            $filas[] = ['Celador (rol 6)', $correo, $e->conjunto['celadores'][$k] === $deTurno ? 'en turno ahora' : ''];
        }

        $compradores = $e->cuentas[1];
        $filas[] = ['Comprador (rol 1)', $compradores[0], count($compradores).' en total, mismo dominio'];

        $this->command?->info('Demostración sembrada. Todas las cuentas usan la clave de DEMO_QA_PASSWORD.');
        $this->command?->table(['Rol', 'Correo', 'Nota'], $filas);
        $this->command?->table(['Tabla', 'Filas creadas'], collect($e->lote->creadas())->map(fn ($n, $t) => [$t, $n])->values()->all());

        if ($retirado !== []) {
            $this->command?->line('Antes se retiró la corrida anterior: '.array_sum($retirado).' filas.');
        }

        $this->command?->line('Para retirarla: DEMO_PURGAR=1 php artisan db:seed --class=DemostracionQaSeeder');
    }

    /** @param array<string, int> $retirado */
    private function informarPurga(array $retirado): void
    {
        $this->command?->info($retirado === [] ? 'No había datos de demostración.' : 'Retirados los datos de demostración.');
        $this->command?->table(['Tabla', 'Filas borradas'], collect($retirado)->map(fn ($n, $t) => [$t, $n])->values()->all());
    }
}
