<?php

namespace App\Console\Commands;

use App\Events\DomiciliaryLocationUpdated;
use App\Models\Order\OrderGeolocation;
use Illuminate\Console\Command;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * MOTOS DE MENTIRA PARA VER LA TORRE DE CONTROL.
 *
 * Pone a los domiciliarios de una tienda a dar vueltas por Barranquilla: cada
 * pocos segundos guarda una posición nueva de cada uno y la anuncia por
 * `negocio.{id}.torre`, igual que lo hace el teléfono de verdad. Así se ve el
 * mapa moverse sin tener a nadie en la calle.
 *
 * Solo fuera de producción: en producción inventaría dónde está una persona.
 *
 * Las posiciones quedan con `state = 9` para poder borrarlas sin tocar las
 * reales: `php artisan torre:simular --limpiar`.
 */
class SimularTorre extends Command
{
    protected $signature = 'torre:simular
        {negocio=1 : busines_id de la tienda}
        {--vueltas=150 : Cuántas posiciones manda cada moto}
        {--cada=3 : Segundos entre una vuelta y la siguiente}
        {--extra=0 : Crea N domiciliarios de prueba, para ver la torre con una flota grande}
        {--limpiar : Borra las posiciones y los domiciliarios simulados y sale}';

    protected $description = 'Mueve a los domiciliarios de una tienda por Barranquilla para probar la torre (solo local)';

    private const SIMULADA = 9;

    /** Así se reconocen los domiciliarios de prueba para borrarlos. */
    private const DOMINIO = '@simulacion-torre.test';

    private const NOMBRES = [
        'Kevin Ariza', 'Laura Pacheco', 'Andrés Charris', 'Yeison Barrios', 'Daniela Orozco',
        'Jhon Mercado', 'Valentina Rúa', 'Carlos Polo', 'Mairen De Ávila', 'Luis Fontalvo',
        'Sharon Escorcia', 'Deiner Cantillo',
    ];

    /** Centro de Barranquilla, más o menos el Prado. */
    private const CENTRO = [10.9878, -74.7889];

    public function handle(): int
    {
        // Lista PERMITIDA y no prohibida: en un staging con datos reales
        // escribiría posiciones falsas de domiciliarios reales.
        if (!app()->environment(['local', 'testing'])) {
            $this->error('No se simulan posiciones en producción.');

            return self::FAILURE;
        }

        if ($this->option('limpiar')) {
            $n = OrderGeolocation::where('state', self::SIMULADA)->delete();
            $d = $this->borrarDePrueba();
            $this->info("Borradas {$n} posiciones simuladas y {$d} domiciliarios de prueba.");

            return self::SUCCESS;
        }

        $negocio = (int) $this->argument('negocio');
        $this->crearDePrueba($negocio, max(0, min(12, (int) $this->option('extra'))));
        $ids = DB::table('business_domiciliary')
            ->where('busines_id', $negocio)
            ->where('state', 1)
            ->pluck('domiciliary_id')
            ->map(fn ($id) => (int) $id)
            ->all();

        if ($ids === []) {
            $this->error("La tienda {$negocio} no tiene domiciliarios vinculados.");

            return self::FAILURE;
        }

        // Cada moto arranca en un punto al azar a unos 3 km del centro, con
        // un rumbo propio que va torciendo poco a poco.
        $motos = [];
        foreach ($ids as $id) {
            $motos[$id] = [
                'lat'   => self::CENTRO[0] + $this->azar(-0.025, 0.025),
                'lng'   => self::CENTRO[1] + $this->azar(-0.025, 0.025),
                'rumbo' => $this->azar(0, 2 * M_PI),
            ];
        }

        $vueltas = max(1, (int) $this->option('vueltas'));
        $cada = max(1, (int) $this->option('cada'));

        $this->info(count($ids) . " motos dando vueltas por Barranquilla. Ctrl+C para parar.");

        for ($v = 1; $v <= $vueltas; $v++) {
            foreach ($motos as $id => &$m) {
                // 60–140 m por vuelta: lo que avanza una moto en ciudad.
                $m['rumbo'] += $this->azar(-0.6, 0.6);
                $paso = $this->azar(0.0006, 0.0013);
                $m['lat'] += cos($m['rumbo']) * $paso;
                $m['lng'] += sin($m['rumbo']) * $paso;

                // Si se aleja mucho del centro, da la vuelta.
                if (abs($m['lat'] - self::CENTRO[0]) > 0.04 || abs($m['lng'] - self::CENTRO[1]) > 0.04) {
                    $m['rumbo'] += M_PI;
                }

                $geo = OrderGeolocation::create([
                    'domiciliary_id' => $id,
                    'orderSales_id'  => null,
                    'latitude'       => round($m['lat'], 6),
                    'longitude'      => round($m['lng'], 6),
                    'state'          => self::SIMULADA,
                ]);

                try {
                    broadcast(new DomiciliaryLocationUpdated($geo, $negocio));
                } catch (\Throwable $e) {
                    $this->warn('Reverb no respondió: ' . $e->getMessage());
                }
            }
            unset($m);

            $this->line("Vuelta {$v}/{$vueltas}");

            if ($v < $vueltas) {
                sleep($cada);
            }
        }

        $this->info('Listo. Para borrar lo simulado: php artisan torre:simular --limpiar');

        return self::SUCCESS;
    }

    /** Domiciliarios de mentira vinculados a la tienda: unos libres, otros fuera de turno. */
    private function crearDePrueba(int $negocio, int $cuantos): void
    {
        for ($i = 0; $i < $cuantos; $i++) {
            $correo = 'moto' . ($i + 1) . self::DOMINIO;
            if (DB::table('user')->where('email', $correo)->exists()) {
                continue;
            }

            DB::transaction(function () use ($i, $correo, $negocio) {
                $userId = DB::table('user')->insertGetId([
                    'name'              => self::NOMBRES[$i],
                    'email'             => $correo,
                    'password'          => bcrypt(Str::random(32)),
                    'phone'             => '30' . str_pad((string) (10000000 + $i * 7919), 8, '0', STR_PAD_LEFT),
                    'rol'               => 3,
                    'state'             => 1,
                    'qualification'     => 0,
                    'email_verified_at' => now(),
                    'created_at'        => now(),
                    'updated_at'        => now(),
                ]);

                $domiId = DB::table('domiciliary')->insertGetId([
                    'user_id'       => $userId,
                    'document'      => (string) (900000000 + $i),
                    'available'     => $i % 3 === 2 ? 0 : 1,
                    'qualification' => 0,
                    'state'         => 1,
                ], 'domiciliary_id');

                DB::table('business_domiciliary')->insert([
                    'busines_id' => $negocio, 'domiciliary_id' => $domiId, 'state' => 1,
                ]);
            });
        }
    }

    private function borrarDePrueba(): int
    {
        $users = DB::table('user')->where('email', 'like', '%' . self::DOMINIO)->pluck('user_id');
        $domis = DB::table('domiciliary')->whereIn('user_id', $users)->pluck('domiciliary_id');

        DB::transaction(function () use ($users, $domis) {
            OrderGeolocation::whereIn('domiciliary_id', $domis)->delete();
            DB::table('business_domiciliary')->whereIn('domiciliary_id', $domis)->delete();
            DB::table('domiciliary')->whereIn('domiciliary_id', $domis)->delete();
            DB::table('personal_access_tokens')->where('tokenable_type', User::class)
                ->whereIn('tokenable_id', $users)->delete();
            DB::table('user')->whereIn('user_id', $users)->delete();
        });

        return $domis->count();
    }

    private function azar(float $min, float $max): float
    {
        return $min + mt_rand() / mt_getrandmax() * ($max - $min);
    }
}
