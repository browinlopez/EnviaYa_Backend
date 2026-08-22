<?php

namespace Database\Seeders;

use App\Services\Ajustes;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Pedidos EN CURSO, para poder ver funcionando el panel del tendero.
 *
 * `DemoSeeder` deja una base con historia: pedidos entregados y cancelados. Lo
 * que no deja es nada sin aceptar ni en camino — que son justo las dos columnas
 * donde el tendero trabaja—, así que el tablero abre vacío y no se puede probar
 * ni aceptar, ni despachar, ni ver un pedido ponerse en rojo por llevar media
 * hora esperando.
 *
 * SÓLO PARA DESARROLLO. No lo llama `DatabaseSeeder`: se corre a mano.
 *
 *     php artisan db:seed --class=PedidosEnCursoDemoSeeder
 *
 * Al terminar imprime el DELETE exacto con lo que creó. No se marcan con
 * `currency` porque la columna es de tres caracteres.
 */
class PedidosEnCursoDemoSeeder extends Seeder
{
    /**
     * Qué crear en cada negocio: [estado, minutos de antigüedad, en efectivo].
     *
     * Las antigüedades están elegidas para que caiga al menos uno por encima de
     * los treinta minutos sin aceptar, que es el umbral con el que la tarjeta
     * se pone en rojo. Sin eso, ese aviso no se puede ver nunca en local.
     */
    private const PLAN = [
        1 => [[1, 4, true], [1, 41, true], [2, 22, false], [3, 55, true]],
        4 => [[1, 7, true], [1, 36, false], [2, 15, true], [3, 48, false]],
        5 => [[1, 2, false], [2, 27, true], [3, 63, true]],
    ];

    public function run(): void
    {
        $tarifa   = (float) (Ajustes::valor('operacion.tarifa_domicilio') ?? 5000);
        $reparto  = (float) (Ajustes::valor('operacion.reparto_domiciliario') ?? 0.75);
        $comision = (float) (Ajustes::valor('operacion.comision_plataforma') ?? 0.03);

        $creados = [];

        foreach (self::PLAN as $businessId => $filas) {
            if (!DB::table('business')->where('busines_id', $businessId)->exists()) {
                continue;
            }

            $productos = DB::table('products_business as pb')
                ->join('products as p', 'p.products_id', '=', 'pb.products_id')
                ->where('pb.busines_id', $businessId)
                ->inRandomOrder()->limit(12)
                ->get(['p.products_id', 'pb.price']);

            // Un negocio sin catálogo no puede tener pedidos con detalle, y un
            // pedido sin líneas se ve roto en el panel.
            if ($productos->isEmpty()) {
                $this->command?->warn("Negocio {$businessId}: sin productos, se omite.");
                continue;
            }

            $compradores = DB::table('buyer')->inRandomOrder()->limit(10)->pluck('buyer_id');

            if ($compradores->isEmpty()) {
                $this->command?->warn('No hay compradores en la base. Corre antes DemoSeeder.');
                return;
            }

            $domis = DB::table('business_domiciliary')
                ->where('busines_id', $businessId)
                ->pluck('domiciliary_id');

            foreach ($filas as $i => [$estado, $edad, $efectivo]) {
                $creados[] = $this->crear(
                    $businessId,
                    $estado,
                    $edad,
                    $efectivo,
                    $productos,
                    $compradores[$i % $compradores->count()],
                    $domis,
                    compact('tarifa', 'reparto', 'comision'),
                );
            }
        }

        $this->informar($creados);
    }

    private function crear(
        int $businessId,
        int $estado,
        int $edad,
        bool $efectivo,
        Collection $productos,
        int $buyerId,
        Collection $domis,
        array $tarifas,
    ): int {
        $lineas = $productos->random(min(random_int(1, 3), $productos->count()));

        // `random()` devuelve el elemento suelto cuando se pide uno solo.
        if (!$lineas instanceof Collection) {
            $lineas = collect([$lineas]);
        }

        $subtotal = 0;
        $detalle  = [];

        foreach ($lineas as $p) {
            $cant = random_int(1, 3);
            $subtotal += (float) $p->price * $cant;
            $detalle[] = [
                'product_id' => $p->products_id,
                'amount'     => $cant,
                'unit_price' => $p->price,
            ];
        }

        $cuando = now()->subMinutes($edad);

        $id = DB::table('orderssales')->insertGetId([
            'buyer_id'   => $buyerId,
            'busines_id' => $businessId,
            // En camino se le asigna alguien, si el negocio tiene a quién.
            'domiciliary_id' => $estado === 3 && $domis->isNotEmpty() ? $domis->random() : null,
            'address_id' => null,
            'methods_id' => $efectivo ? 1 : 2,
            'forms_id'   => $efectivo ? 1 : 2,
            'subtotal'   => $subtotal,
            'discount'   => 0,
            'domicilio'  => $tarifas['tarifa'],
            'total'      => $subtotal + $tarifas['tarifa'],
            // Congelados al crear, igual que en producción.
            'domiciliary_fee'  => round($tarifas['tarifa'] * $tarifas['reparto']),
            'platform_fee'     => round($subtotal * $tarifas['comision'], 2),
            'delivery_subsidy' => 0,
            'cash_due'         => 0,
            'sale_date'        => $cuando,
            'state'            => $estado,
            // Contra entrega nace pendiente; el pagado en línea, confirmado.
            'payment_state'    => $efectivo ? 'pending' : 'paid',
            'pickup'           => 0,
            'is_scheduled'     => 0,
            'dispatched_at'    => $estado === 3 ? $cuando->copy()->addMinutes(12) : null,
            'promised_minutes' => 45,
            'created_at'       => $cuando,
            'updated_at'       => $cuando,
        ], 'orderSales_id');

        foreach ($detalle as $d) {
            DB::table('orderssales_detail')->insert($d + ['orderSales_id' => $id]);
        }

        return $id;
    }

    private function informar(array $creados): void
    {
        if (!$creados) {
            $this->command?->warn('No se creó ningún pedido.');
            return;
        }

        $lista = implode(',', $creados);

        $this->command?->info('Pedidos de ejemplo creados: ' . count($creados));
        $this->command?->line('Para borrarlos:');
        $this->command?->line("  DELETE FROM orderssales_detail WHERE orderSales_id IN ({$lista});");
        $this->command?->line("  DELETE FROM orderssales        WHERE orderSales_id IN ({$lista});");
    }
}
