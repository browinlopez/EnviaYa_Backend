<?php

namespace Database\Seeders;

use App\Models\Order\OrdersSales;
use App\Services\Ajustes;
use App\Services\CustodiaDeEfectivo;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * UNA FLOTA CON PLATA EN LA CALLE, para poder ver funcionando «Efectivo».
 *
 * La base de desarrollo tiene UN domiciliario y ningún recaudo pendiente, así
 * que la pantalla del tendero abre con dos ceros, una sola fila y ninguna
 * entrega. Con eso no se ve nada de lo que la pantalla hace: ni el reparto de
 * lo que cada uno debe, ni el orden por deuda, ni la insignia de «Al día» al
 * lado de los que sí deben, ni el registro de últimas entregas, ni el tope que
 * impide recibir más de lo cobrado en TUS pedidos.
 *
 * Deja:
 *   · cuatro domiciliarios más, vinculados a la tienda de prueba;
 *   · pedidos ENTREGADOS y en efectivo repartidos entre los cinco;
 *   · el recaudo apuntado con el MISMO servicio que usa producción
 *     --`CustodiaDeEfectivo::registrarRecaudo`--, no a mano en la tabla:
 *     un dato de prueba que se escribe por un camino distinto al de verdad
 *     prueba ese camino, no la app;
 *   · dos entregas en mostrador de hoy --una parcial-- para que «Recibido hoy»
 *     y «Últimas entregas» tengan contenido, y uno quede al día.
 *
 * SÓLO PARA DESARROLLO. No lo llama `DatabaseSeeder`: se corre a mano.
 *
 *     php artisan db:seed --class=EfectivoDemoSeeder
 *
 * Al terminar imprime los DELETE exactos con lo que creó.
 */
class EfectivoDemoSeeder extends Seeder
{
    /** La tienda de prueba, la del tendero@gmail.com. */
    private const NEGOCIO = 1;

    /**
     * Los que faltan. [nombre, teléfono, cédula, cuántos pedidos, al día].
     *
     * Las cuentas se dan DE ALTA con contraseña conocida porque esta gente
     * también entra a su propia app --el acuerdo, los papeles, el SOS-- y sin
     * poder entrar sólo sirven de relleno en una lista.
     */
    private const FLOTA = [
        ['Wilmer Ospina', '3016742201', '1045991201', 4],
        ['Yeison Martínez', '3127788340', '1045991202', 3],
        ['Dayana Rivas', '3204419987', '1045991203', 2],
        ['Breiner Polo', '3159902214', '1045991204', 3],
    ];

    public function run(): void
    {
        if (! DB::table('business')->where('busines_id', self::NEGOCIO)->exists()) {
            $this->command?->error('No existe la tienda '.self::NEGOCIO.'. Corre antes DemoSeeder.');

            return;
        }

        $productos = DB::table('products_business as pb')
            ->join('products as p', 'p.products_id', '=', 'pb.products_id')
            ->where('pb.busines_id', self::NEGOCIO)
            ->inRandomOrder()->limit(20)
            ->get(['p.products_id', 'pb.price']);

        $compradores = DB::table('buyer')->inRandomOrder()->limit(10)->pluck('buyer_id');

        if ($productos->isEmpty() || $compradores->isEmpty()) {
            $this->command?->error('La tienda no tiene catálogo o no hay compradores. Corre antes DemoSeeder.');

            return;
        }

        $creados = ['user' => [], 'domiciliary' => [], 'orden' => []];

        /* ---------- 1. LOS DOMICILIARIOS QUE FALTAN ---------- */
        $domis = [];

        foreach (self::FLOTA as [$nombre, $telefono, $cedula, $cuantos]) {
            $correo = 'domi.'.strtolower(explode(' ', $nombre)[0]).'@vecipaya.test';

            $existe = DB::table('user')->where('email', $correo)->value('user_id');

            if ($existe) {
                $this->command?->warn("Ya existe {$correo}, se reutiliza.");
                $userId = (int) $existe;
            } else {
                $userId = (int) DB::table('user')->insertGetId([
                    'name' => $nombre,
                    'email' => $correo,
                    'email_verified_at' => now(),
                    'password' => Hash::make('password123'),
                    'phone' => $telefono,
                    'rol' => 3,
                    'state' => 1,
                    'qualification' => 0,
                    'created_at' => now(),
                    'updated_at' => now(),
                ], 'user_id');
                $creados['user'][] = $userId;
            }

            $domiId = DB::table('domiciliary')->where('user_id', $userId)->value('domiciliary_id');

            if (! $domiId) {
                $domiId = (int) DB::table('domiciliary')->insertGetId([
                    'user_id' => $userId,
                    'available' => 1,
                    'document' => $cedula,
                    // Firmado: sin acuerdo no puede recibir pedidos, y entonces
                    // no tendría sentido que tuviera efectivo cobrado.
                    'contract_signed_at' => now()->subDays(random_int(20, 90)),
                    'contract_city' => 'Barranquilla',
                    'qualification' => round(random_int(40, 50) / 10, 2),
                    'state' => 1,
                    'registrado_por_negocio' => self::NEGOCIO,
                ], 'domiciliary_id');
                $creados['domiciliary'][] = $domiId;
            }

            DB::table('business_domiciliary')->updateOrInsert(
                ['busines_id' => self::NEGOCIO, 'domiciliary_id' => $domiId],
                ['state' => 1],
            );

            $domis[$domiId] = $cuantos;
        }

        /* El que ya estaba en la base también reparte y también debe. */
        $viejo = DB::table('business_domiciliary')
            ->where('busines_id', self::NEGOCIO)
            ->whereNotIn('domiciliary_id', array_keys($domis))
            ->value('domiciliary_id');

        if ($viejo) {
            $domis[(int) $viejo] = 3;
        }

        /* ---------- 2. PEDIDOS ENTREGADOS Y EN EFECTIVO ---------- */
        $tarifa = (float) (Ajustes::valor('operacion.tarifa_domicilio') ?? 5000);
        $reparto = (float) (Ajustes::valor('operacion.reparto_domiciliario') ?? 0.75);
        $comision = (float) (Ajustes::valor('operacion.comision_plataforma') ?? 0.03);

        $custodia = app(CustodiaDeEfectivo::class);
        $i = 0;

        foreach ($domis as $domiId => $cuantos) {
            for ($n = 0; $n < $cuantos; $n++) {
                $id = $this->crearPedido(
                    $domiId,
                    $productos,
                    (int) $compradores[$i++ % $compradores->count()],
                    /* Repartidos en los últimos tres días para que las fechas
                       del registro no salgan todas iguales. */
                    random_int(40, 3 * 24 * 60),
                    compact('tarifa', 'reparto', 'comision'),
                );

                $creados['orden'][] = $id;

                $orden = OrdersSales::find($id);
                $custodia->registrarRecaudo($orden);
                $orden->save();
            }
        }

        /* ---------- 3. DOS ENTREGAS EN MOSTRADOR, DE HOY ---------- */
        $tendero = (int) DB::table('user')->where('email', 'tendero@gmail.com')->value('user_id');
        $entregas = 0;

        foreach (array_slice(array_keys($domis), 0, 2) as $k => $domiId) {
            $debe = $custodia->pendienteConLaTienda(self::NEGOCIO, (int) $domiId);

            if ($debe <= 0) {
                continue;
            }

            /* Al primero se le recibe TODO --queda al día, que es el caso que
               enseña la insignia-- y al segundo sólo una parte, que es lo que
               de verdad pasa cuando el domiciliario entrega lo que lleva
               suelto y se queda con un billete grande. */
            $monto = $k === 0 ? $debe : floor($debe / 2);

            if ($monto <= 0) {
                continue;
            }

            $custodia->recibirEnMostrador(
                self::NEGOCIO,
                (int) $domiId,
                (float) $monto,
                $tendero,
                $k === 0 ? 'Entregó todo al cerrar' : 'Abono, le quedó un billete grande',
            );
            $entregas++;
        }

        $this->informar($creados, $entregas);
    }

    /** Un pedido entregado, pagado en efectivo, con su detalle. */
    private function crearPedido(
        int $domiId,
        $productos,
        int $buyerId,
        int $edadEnMinutos,
        array $tarifas,
    ): int {
        $lineas = $productos->random(min(random_int(1, 4), $productos->count()));

        if (! $lineas instanceof Collection) {
            $lineas = collect([$lineas]);
        }

        $subtotal = 0;
        $detalle = [];

        foreach ($lineas as $p) {
            $cant = random_int(1, 3);
            $subtotal += (float) $p->price * $cant;
            $detalle[] = [
                'product_id' => $p->products_id,
                'amount' => $cant,
                'unit_price' => $p->price,
            ];
        }

        $cuando = now()->subMinutes($edadEnMinutos);
        $total = $subtotal + $tarifas['tarifa'];

        $id = DB::table('orderssales')->insertGetId([
            'buyer_id' => $buyerId,
            'busines_id' => self::NEGOCIO,
            'domiciliary_id' => $domiId,
            'address_id' => null,
            'methods_id' => 1,
            'forms_id' => 1,
            'subtotal' => $subtotal,
            'discount' => 0,
            'domicilio' => $tarifas['tarifa'],
            'total' => $total,
            'domiciliary_fee' => round($tarifas['tarifa'] * $tarifas['reparto']),
            'platform_fee' => round($subtotal * $tarifas['comision'], 2),
            'delivery_subsidy' => 0,
            /* Lo pone `registrarRecaudo`; aquí nace en cero como en producción. */
            'cash_due' => 0,
            'sale_date' => $cuando,
            'state' => OrdersSales::ENTREGADO,
            /* Contra entrega: el dinero existe, pero lo tiene el domiciliario,
               no la plataforma. Por eso sigue «pending» hasta la liquidación. */
            'payment_state' => 'pending',
            'pickup' => 0,
            'is_scheduled' => 0,
            'dispatched_at' => $cuando->copy()->addMinutes(9),
            'delivery_date' => $cuando->copy()->addMinutes(random_int(18, 50)),
            'promised_minutes' => 45,
            'created_at' => $cuando,
            'updated_at' => $cuando,
        ], 'orderSales_id');

        foreach ($detalle as $d) {
            DB::table('orderssales_detail')->insert($d + ['orderSales_id' => $id]);
        }

        return $id;
    }

    private function informar(array $creados, int $entregas): void
    {
        $ordenes = implode(',', $creados['orden']);
        $domis = implode(',', $creados['domiciliary']);
        $users = implode(',', $creados['user']);

        $this->command?->info('Pedidos en efectivo creados: '.count($creados['orden']));
        $this->command?->info('Domiciliarios nuevos: '.count($creados['domiciliary']));
        $this->command?->info('Entregas en mostrador de hoy: '.$entregas);
        $this->command?->newLine();
        $this->command?->warn('Para deshacerlo, EN ESTE ORDEN:');

        if ($ordenes) {
            $this->command?->line("DELETE FROM cash_movements WHERE order_id IN ({$ordenes});");
            $this->command?->line("DELETE FROM orderssales_detail WHERE orderSales_id IN ({$ordenes});");
            $this->command?->line("DELETE FROM orderssales WHERE orderSales_id IN ({$ordenes});");
        }

        if ($domis) {
            $this->command?->line("DELETE FROM cash_deposits WHERE domiciliary_id IN ({$domis});");
            $this->command?->line("DELETE FROM business_domiciliary WHERE domiciliary_id IN ({$domis});");
            $this->command?->line("DELETE FROM domiciliary WHERE domiciliary_id IN ({$domis});");
        }

        if ($users) {
            $this->command?->line("DELETE FROM user WHERE user_id IN ({$users});");
        }
    }
}
