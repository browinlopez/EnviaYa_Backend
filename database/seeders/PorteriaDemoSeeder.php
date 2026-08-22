<?php

namespace Database\Seeders;

use App\Models\Domiciliary;
use App\Models\Rol;
use App\Models\User;
use App\Services\Ajustes;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * Un domiciliario con pedidos EN CAMINO a un conjunto, para poder probar la
 * portería del panel de aliados.
 *
 * SIN ESTO LA PORTERÍA NO SE PUEDE PROBAR. Responde consultando pedidos en
 * estado 3 cuya DIRECCIÓN DE ENTREGA apunte al conjunto:
 *
 *     orderssales.state = 3
 *     JOIN user_address ON address_id
 *     WHERE user_address.complex_id = <el del celador>
 *
 * Y en la base local no hay ni una dirección con `complex_id`: la columna se
 * añadió con los conjuntos y los datos de demo son anteriores. Así que la
 * pantalla responde «no tiene pedidos en el conjunto» a todo el mundo, que es
 * la respuesta correcta sobre unos datos que no existen — y se lee como si
 * estuviera rota.
 *
 * Crea, si no existen:
 *   · un comprador que vive en el conjunto, con torre y apartamento;
 *   · dos pedidos suyos en camino, de negocios distintos, con el mismo
 *     domiciliario.
 *
 * Dos y no uno a propósito: la respuesta de la portería es una LISTA con la
 * torre y el apartamento de cada uno, y con un solo pedido no se ve que lo sea.
 *
 * SÓLO PARA DESARROLLO. Se corre a mano:
 *
 *     php artisan db:seed --class=PorteriaDemoSeeder
 */
class PorteriaDemoSeeder extends Seeder
{
    /** Conjunto Villa Carolina: es el que administra la cuenta de pruebas. */
    private const CONJUNTO = 3;

    /** Yuranis Castro. Disponible y con dos negocios asignados. */
    private const DOMICILIARIO = 6;

    private const CORREO = 'vecina.villacarolina@demo.example.com';

    public function run(): void
    {
        $conjunto = DB::table('residential_complexes')
            ->where('complex_id', self::CONJUNTO)
            ->first();

        if (!$conjunto) {
            $this->command?->error('No existe el conjunto ' . self::CONJUNTO . '.');
            return;
        }

        $domiciliario = Domiciliary::find(self::DOMICILIARIO);

        if (!$domiciliario) {
            $this->command?->error('No existe el domiciliario ' . self::DOMICILIARIO . '.');
            return;
        }

        $buyerId   = $this->comprador($conjunto);
        $direccion = $this->direccion($buyerId, $conjunto);

        $negocios = DB::table('business_domiciliary')
            ->where('domiciliary_id', self::DOMICILIARIO)
            ->pluck('busines_id');

        if ($negocios->isEmpty()) {
            $this->command?->error('Ese domiciliario no está asignado a ningún negocio.');
            return;
        }

        $creados = [];

        foreach ($negocios->take(2) as $i => $businessId) {
            $creados[] = $this->pedidoEnCamino(
                $businessId,
                $buyerId,
                $direccion,
                // Uno en efectivo y otro pagado: en la portería no cambia nada,
                // pero en el tablero del tendero se ven los dos casos.
                $i === 0,
            );
        }

        $this->informar($domiciliario, $conjunto, $creados);
    }

    /** El comprador que vive en el conjunto. */
    private function comprador($conjunto): int
    {
        Rol::firstOrCreate(['rol_id' => 1], ['name' => 'comprador', 'guard_name' => 'web']);

        $user = User::firstOrCreate(
            ['email' => self::CORREO],
            [
                'name'              => 'Vecina de Villa Carolina',
                'password'          => Hash::make('Local2026*'),
                'phone'             => '300 555 0143',
                'rol'               => 1,
                'state'             => 1,
                'email_verified_at' => now(),
            ],
        );

        $buyerId = DB::table('buyer')->where('user_id', $user->user_id)->value('buyer_id');

        if (!$buyerId) {
            $buyerId = DB::table('buyer')->insertGetId([
                'user_id'            => $user->user_id,
                'qualification'      => 0,
                'belongs_to_complex' => 1,
                'state'              => 1,
            ]);
        }

        // El pivote comprador-conjunto, que es lo que cuenta «Residentes».
        $yaVinculado = DB::table('buyer_complex')
            ->where('buyer_id', $buyerId)
            ->where('complex_id', $conjunto->complex_id)
            ->exists();

        if (!$yaVinculado) {
            DB::table('buyer_complex')->insert([
                'buyer_id'   => $buyerId,
                'complex_id' => $conjunto->complex_id,
            ]);
        }

        return $buyerId;
    }

    /**
     * Su dirección DENTRO del conjunto.
     *
     * Es la fila que hace funcionar todo esto: sin `complex_id` acá, la
     * portería no encuentra el pedido por más que exista.
     */
    private function direccion(int $buyerId, $conjunto): int
    {
        $userId = DB::table('buyer')->where('buyer_id', $buyerId)->value('user_id');

        $existente = DB::table('user_address')
            ->where('user_id', $userId)
            ->where('complex_id', $conjunto->complex_id)
            ->value('address_id');

        if ($existente) {
            return $existente;
        }

        return DB::table('user_address')->insertGetId([
            'user_id'    => $userId,
            'address'    => $conjunto->address ?: 'Conjunto Villa Carolina',
            'complex_id' => $conjunto->complex_id,
            'tower'      => '4',
            'apartment'  => '1203',
            // Hereda las coordenadas del conjunto, igual que hace el registro.
            'latitude'   => $conjunto->latitude ?? null,
            'longitude'  => $conjunto->longitude ?? null,
            'state'      => 1,
        ]);
    }

    private function pedidoEnCamino(int $businessId, int $buyerId, int $addressId, bool $efectivo): int
    {
        $tarifa   = (float) (Ajustes::valor('operacion.tarifa_domicilio') ?? 5000);
        $reparto  = (float) (Ajustes::valor('operacion.reparto_domiciliario') ?? 0.75);
        $comision = (float) (Ajustes::valor('operacion.comision_plataforma') ?? 0.03);

        $productos = DB::table('products_business as pb')
            ->join('products as p', 'p.products_id', '=', 'pb.products_id')
            ->where('pb.busines_id', $businessId)
            ->inRandomOrder()->limit(3)
            ->get(['p.products_id', 'pb.price']);

        $subtotal = 0;
        $detalle  = [];

        foreach ($productos as $p) {
            $cant = random_int(1, 2);
            $subtotal += (float) $p->price * $cant;
            $detalle[] = [
                'product_id' => $p->products_id,
                'amount'     => $cant,
                'unit_price' => $p->price,
            ];
        }

        // Un negocio sin catálogo dejaría un pedido de cero pesos.
        if ($subtotal <= 0) {
            $subtotal = 25000;
        }

        $cuando = now()->subMinutes(random_int(12, 30));

        $id = DB::table('orderssales')->insertGetId([
            'buyer_id'       => $buyerId,
            'busines_id'     => $businessId,
            'domiciliary_id' => self::DOMICILIARIO,
            'address_id'     => $addressId,
            'methods_id'     => $efectivo ? 1 : 2,
            'forms_id'       => $efectivo ? 1 : 2,
            'subtotal'       => $subtotal,
            'discount'       => 0,
            'domicilio'      => $tarifa,
            'total'          => $subtotal + $tarifa,
            'domiciliary_fee'  => round($tarifa * $reparto),
            'platform_fee'     => round($subtotal * $comision, 2),
            'delivery_subsidy' => 0,
            'cash_due'         => 0,
            'sale_date'        => $cuando,
            // 3 = en camino. Es el único estado que la portería mira: ya lo
            // recogió y todavía no lo entregó.
            'state'            => 3,
            'payment_state'    => $efectivo ? 'pending' : 'paid',
            'pickup'           => 0,
            'is_scheduled'     => 0,
            'dispatched_at'    => $cuando->copy()->addMinutes(8),
            'promised_minutes' => 45,
            'created_at'       => $cuando,
            'updated_at'       => $cuando,
        ], 'orderSales_id');

        foreach ($detalle as $d) {
            DB::table('orderssales_detail')->insert($d + ['orderSales_id' => $id]);
        }

        return $id;
    }

    private function informar(Domiciliary $domiciliario, $conjunto, array $creados): void
    {
        $nombre = DB::table('user')->where('user_id', $domiciliario->user_id)->value('name');

        $this->command?->newLine();
        $this->command?->info('Escenario de portería listo.');

        $this->command?->table(['Campo', 'Valor'], [
            ['Conjunto', "{$conjunto->name} (#{$conjunto->complex_id})"],
            ['Domiciliario', $nombre],
            ['Cédula', $domiciliario->document ?? 'sin documento'],
            ['Entrega en', 'Torre 4 · Apto 1203'],
            ['Pedidos en camino', implode(', ', array_map(fn ($i) => "#{$i}", $creados))],
        ]);

        $this->command?->newLine();
        $this->command?->line('En el panel de aliados → Portería, busca por esa cédula.');
        $this->command?->line('El código del QR lo genera su app y dura 5 minutos;');
        $this->command?->line('la cédula no caduca, así que para probar sirve mejor.');
    }
}
