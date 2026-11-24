<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Order\OrdersSales;
use App\Support\ProductTypeSchema;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * ==========================================================================
 * PEDIDOS DE VERDAD PARA LAS DOS DROGUERÍAS.
 *
 * Los que ya había estaban HUECOS: totales, domicilio y comisión, pero
 * **cero renglones** —medido: 0 filas en `orderssales_detail` para los 15
 * pedidos del negocio 3—. Un pedido sin productos no se puede abrir, no se
 * puede preparar y no enseña nada; y el que lo mire se pregunta si la
 * pantalla está rota.
 *
 * Y de los 15, trece estaban ENTREGADOS y uno cancelado: la pantalla de
 * Pedidos —que muestra los ACTIVOS— salía con uno solo.
 *
 * --------------------------------------------------------------------------
 * LOS ESTADOS SE REPARTEN A PROPÓSITO.
 *
 * El tendero necesita ver los tres momentos de su día, no quince iguales:
 *
 *   NUEVO       el que espera que lo acepte. Es el que duele si se queda ahí.
 *   PREPARANDO  aceptado, armándose en el mostrador.
 *   EN_CAMINO   despachado, con un domiciliario encima.
 *
 * --------------------------------------------------------------------------
 * Y LOS MEDIOS DE PAGO TAMBIÉN, PORQUE CAMBIAN LA PANTALLA.
 *
 * Efectivo contra entrega lleva `cash_due` y `cash_tendered`: sin eso, la
 * pantalla de vuelto del domiciliario no tiene qué calcular. Transferencia
 * anticipada no lleva vuelto, y el crédito de la tienda mueve el cupo del
 * cliente. Sembrar los tres con el mismo medio dejaría dos caminos sin mirar.
 *
 * --------------------------------------------------------------------------
 * LOS TOTALES SE CALCULAN, NO SE INVENTAN.
 *
 * `subtotal` sale de sumar los renglones a los precios REALES que tiene esa
 * sede. Un total escrito a mano que no cuadre con sus productos es un dato de
 * prueba mintiendo: el día que alguien revise por qué una cuenta no da, la
 * culpa parecerá del código.
 * ========================================================================== */
class PedidosDeDrogueriaSeeder extends Seeder
{
    /** Pago contra entrega. */
    private const CONTRA_ENTREGA = 1;

    /** Pago anticipado. */
    private const ANTICIPADO = 2;

    private const EFECTIVO = 1;

    private const TRANSFERENCIA = 4;

    private const NEQUI = 6;

    private const FIADO = 7;

    public function run(): void
    {
        $sedes = DB::table('business')
            ->where('type', ProductTypeSchema::TYPE_PHARMACY)
            ->orderBy('busines_id')
            ->pluck('busines_id')
            ->all();

        if ($sedes === []) {
            $this->command?->error('No hay droguerías en esta base.');

            return;
        }

        foreach ($sedes as $i => $sede) {
            $puestos = $this->paraLaSede((int) $sede, $i === 0);

            $reparados = $this->repararHuecos((int) $sede);

            $nombre = DB::table('business')->where('busines_id', $sede)->value('name');

            $this->command?->info("«{$nombre}»: {$puestos} pedidos nuevos con renglones de verdad.");

            if ($reparados) {
                $this->command?->line("    y {$reparados} que estaban vacíos, rellenados.");
            }
        }

        $this->command?->newLine();
        $this->command?->line('  Activos ahora mismo:');

        foreach ($sedes as $sede) {
            foreach (OrdersSales::ACTIVOS as $estado) {
                $n = DB::table('orderssales')
                    ->where('busines_id', $sede)
                    ->where('state', $estado)
                    ->count();

                if ($n) {
                    $this->command?->line("    negocio {$sede} · estado {$estado} · {$n}");
                }
            }
        }
    }

    /* ------------------------------------------------------------------ */

    /**
     * @param  bool  $principal  La sede del centro lleva más movimiento.
     */
    private function paraLaSede(int $sede, bool $principal): int
    {
        /* Lo que esta sede vende DE VERDAD, con sus precios. */
        $surtido = DB::table('products_business as pb')
            ->join('products as p', 'p.products_id', '=', 'pb.products_id')
            ->where('pb.busines_id', $sede)
            ->where('pb.amount', '>', 0)
            ->orderBy('pb.products_id')
            ->get(['pb.products_id', 'pb.price', 'pb.sale_unit'])
            ->values();

        if ($surtido->isEmpty()) {
            return 0;
        }

        $domiciliarios = DB::table('business_domiciliary')
            ->where('busines_id', $sede)
            ->orderBy('domiciliary_id')
            ->pluck('domiciliary_id')
            ->all();

        $guiones = $principal ? $this->delCentro() : $this->delNorte();

        $puestos = 0;

        foreach ($guiones as $i => $g) {
            if ($this->unPedido($sede, $surtido, $domiciliarios, $g, $i)) {
                $puestos++;
            }
        }

        return $puestos;
    }

    /**
     * Seis pedidos: dos esperando, dos armándose y dos en la calle.
     *
     * `comprador` es el `user_id`; `lleva` son los índices del surtido, para
     * que cada pedido traiga cosas distintas sin depender de qué productos
     * tenga sembrados la base.
     */
    private function delCentro(): array
    {
        return [
            [
                'comprador' => 33, 'estado' => OrdersSales::NUEVO,
                'metodo' => self::EFECTIVO, 'forma' => self::CONTRA_ENTREGA,
                'lleva' => [0, 4, 9], 'hace' => 8,
            ],
            [
                'comprador' => 31, 'estado' => OrdersSales::NUEVO,
                'metodo' => self::NEQUI, 'forma' => self::ANTICIPADO,
                'lleva' => [2, 7], 'hace' => 23,
            ],
            [
                'comprador' => 39, 'estado' => OrdersSales::PREPARANDO,
                'metodo' => self::EFECTIVO, 'forma' => self::CONTRA_ENTREGA,
                'lleva' => [1, 5, 11, 14], 'hace' => 41,
            ],
            [
                'comprador' => 40, 'estado' => OrdersSales::PREPARANDO,
                'metodo' => self::FIADO, 'forma' => self::CONTRA_ENTREGA,
                'lleva' => [3, 8], 'hace' => 55,
            ],
            [
                'comprador' => 32, 'estado' => OrdersSales::EN_CAMINO,
                'metodo' => self::EFECTIVO, 'forma' => self::CONTRA_ENTREGA,
                'lleva' => [6, 10, 13], 'hace' => 72,
            ],
            [
                'comprador' => 41, 'estado' => OrdersSales::EN_CAMINO,
                'metodo' => self::TRANSFERENCIA, 'forma' => self::ANTICIPADO,
                'lleva' => [12, 15], 'hace' => 95,
            ],
        ];
    }

    /** La sede chica: tres pedidos, uno de cada estado. */
    private function delNorte(): array
    {
        return [
            [
                'comprador' => 33, 'estado' => OrdersSales::NUEVO,
                'metodo' => self::EFECTIVO, 'forma' => self::CONTRA_ENTREGA,
                'lleva' => [1, 6], 'hace' => 14,
            ],
            [
                'comprador' => 35, 'estado' => OrdersSales::PREPARANDO,
                'metodo' => self::NEQUI, 'forma' => self::ANTICIPADO,
                'lleva' => [0, 3, 8], 'hace' => 37,
            ],
            [
                'comprador' => 42, 'estado' => OrdersSales::EN_CAMINO,
                'metodo' => self::EFECTIVO, 'forma' => self::CONTRA_ENTREGA,
                'lleva' => [2, 5], 'hace' => 66,
            ],
        ];
    }

    /**
     * LOS PEDIDOS ACTIVOS QUE NO TIENEN NI UN RENGLÓN.
     *
     * No es un apaño para dos filas concretas: es una regla. La base trae
     * pedidos de demostración antiguos con total, domicilio y comisión pero
     * CERO productos, y ésos son los que aparecen en «Pedidos» sin nada
     * dentro. Quien los abra no ve un pedido raro: ve una pantalla rota.
     *
     * Se rellenan con lo que esa sede vende y SE LES RECALCULA EL TOTAL.
     * Dejarles el total viejo —$185.568 para tres cajas de acetaminofén—
     * sería cambiar un dato incoherente por otro, y el día que alguien
     * revise por qué una cuenta no da, la culpa parecerá del código.
     *
     * Sólo los ACTIVOS. Un pedido entregado hace dos meses sin renglones es
     * historia vieja que nadie va a abrir; reescribirle el total sería
     * tocar las cifras de un mes ya cerrado.
     */
    private function repararHuecos(int $sede): int
    {
        $surtido = DB::table('products_business as pb')
            ->where('pb.busines_id', $sede)
            ->where('pb.amount', '>', 0)
            ->orderBy('pb.products_id')
            ->get(['pb.products_id', 'pb.price', 'pb.sale_unit'])
            ->values();

        if ($surtido->isEmpty()) {
            return 0;
        }

        $huecos = DB::table('orderssales as o')
            ->where('o.busines_id', $sede)
            ->whereIn('o.state', OrdersSales::ACTIVOS)
            ->whereNotExists(fn ($q) => $q
                ->selectRaw('1')
                ->from('orderssales_detail as d')
                ->whereColumn('d.orderSales_id', 'o.orderSales_id'),
            )
            ->get(['o.orderSales_id', 'o.buyer_id', 'o.methods_id', 'o.domicilio']);

        foreach ($huecos as $n => $o) {
            $subtotal = 0.0;

            for ($k = 0; $k < 3; $k++) {
                $p = $surtido[($n * 5 + $k * 3) % $surtido->count()];
                $cantidad = 1 + $k;
                $precio = (float) $p->price;
                $subtotal += $precio * $cantidad;

                DB::table('orderssales_detail')->insert([
                    'orderSales_id' => (int) $o->orderSales_id,
                    'product_id' => (int) $p->products_id,
                    'amount' => $cantidad,
                    'unit_price' => $precio,
                    'sale_unit' => $p->sale_unit,
                    'line_total' => $precio * $cantidad,
                ]);
            }

            $domicilio = (float) ($o->domicilio ?: 5000);
            $total = $subtotal + $domicilio;
            $enEfectivo = (int) $o->methods_id === self::EFECTIVO;

            /* Y la dirección, si le faltaba: sin ella el pedido no se puede
               pintar en el mapa ni se le puede trazar la ruta. */
            $direccion = DB::table('user_address as a')
                ->join('buyer as b', 'b.user_id', '=', 'a.user_id')
                ->where('b.buyer_id', $o->buyer_id)
                ->value('a.address_id');

            DB::table('orderssales')
                ->where('orderSales_id', $o->orderSales_id)
                ->update([
                    'subtotal' => $subtotal,
                    'domicilio' => $domicilio,
                    'total' => $total,
                    'cash_due' => $enEfectivo ? $total : 0,
                    'cash_tendered' => $enEfectivo ? ceil($total / 10000) * 10000 : null,
                    'address_id' => DB::raw('COALESCE(address_id, '.((int) ($direccion ?: 0) ?: 'NULL').')'),
                    'updated_at' => now(),
                ]);
        }

        return $huecos->count();
    }

    private function unPedido(int $sede, $surtido, array $domiciliarios, array $g, int $i): bool
    {
        $buyer = DB::table('buyer')->where('user_id', $g['comprador'])->value('buyer_id');
        $direccion = DB::table('user_address')->where('user_id', $g['comprador'])->value('address_id');

        if (! $buyer) {
            return false;
        }

        $cuando = now()->subMinutes($g['hace']);

        $renglones = [];
        $subtotal = 0.0;

        foreach ($g['lleva'] as $n => $indice) {
            $p = $surtido[$indice % $surtido->count()] ?? null;

            if (! $p) {
                continue;
            }

            $cantidad = 1 + ($n % 3);
            $precio = (float) $p->price;
            $subtotal += $precio * $cantidad;

            $renglones[] = [
                'product_id' => (int) $p->products_id,
                'amount' => $cantidad,
                'unit_price' => $precio,
                'sale_unit' => $p->sale_unit,
                'line_total' => $precio * $cantidad,
            ];
        }

        if ($renglones === []) {
            return false;
        }

        $domicilio = 5000.0;
        $total = $subtotal + $domicilio;

        /*
         * El efectivo lleva con cuánto paga el cliente, redondeado hacia arriba
         * al billete de diez mil. Sin esto la pantalla de vuelto no tiene qué
         * calcular y el domiciliario ve «$0 de vuelto» en un pedido en efectivo.
         */
        $enEfectivo = $g['metodo'] === self::EFECTIVO;
        $paga = $enEfectivo ? ceil($total / 10000) * 10000 : null;

        /*
         * SE REPITE POR (sede, comprador, estado), Y NO POR LA HORA.
         *
         * La primera versión buscaba por `created_at`, calculado con `now()`
         * menos unos minutos. Como `now()` se mueve, la segunda corrida no
         * encontraba nada y creaba los nueve pedidos otra vez: medido, 18 donde
         * tenían que haber 9.
         *
         * Una llave de idempotencia que depende del reloj no es una llave. Las
         * tres de abajo son estables, y en el guion no se repite ninguna
         * combinación: cada comprador aparece una sola vez por sede y estado.
         */
        $existente = DB::table('orderssales')
            ->where('busines_id', $sede)
            ->where('buyer_id', $buyer)
            ->where('state', $g['estado'])
            ->where('promised_minutes', 30)
            ->value('orderSales_id');

        $fila = [
            'buyer_id' => $buyer,
            'busines_id' => $sede,
            'domiciliary_id' => $g['estado'] === OrdersSales::EN_CAMINO
                ? ($domiciliarios[$i % max(1, count($domiciliarios))] ?? null)
                : null,
            'address_id' => $direccion,
            'methods_id' => $g['metodo'],
            'forms_id' => $g['forma'],
            'subtotal' => $subtotal,
            'domicilio' => $domicilio,
            'discount' => 0,
            'domiciliary_fee' => 1250,
            'platform_fee' => 0,
            'delivery_subsidy' => 0,
            'total' => $total,
            'cash_due' => $enEfectivo ? $total : 0,
            'cash_tendered' => $paga,
            'weighing_state' => 0,
            'currency' => 'COP',
            'sale_date' => $cuando,
            'state' => $g['estado'],
            'has_review' => 0,
            'payment_state' => $g['forma'] === self::ANTICIPADO ? 'approved' : 'pending',
            'pickup' => 0,
            'is_scheduled' => 0,
            'stock_descontado' => 0,
            'dispatched_at' => $g['estado'] === OrdersSales::EN_CAMINO
                ? $cuando->copy()->addMinutes(12)
                : null,
            'promised_minutes' => 30,
            'created_at' => $cuando,
            'updated_at' => $cuando,
        ];

        if ($existente) {
            DB::table('orderssales')->where('orderSales_id', $existente)->update($fila);
            $pedido = (int) $existente;
        } else {
            $pedido = (int) DB::table('orderssales')->insertGetId($fila, 'orderSales_id');
        }

        /* Los renglones se rehacen enteros: así el subtotal y lo que se ve en
           la pantalla no pueden contradecirse. */
        DB::table('orderssales_detail')->where('orderSales_id', $pedido)->delete();

        foreach ($renglones as $r) {
            DB::table('orderssales_detail')->insert($r + ['orderSales_id' => $pedido]);
        }

        return true;
    }
}
