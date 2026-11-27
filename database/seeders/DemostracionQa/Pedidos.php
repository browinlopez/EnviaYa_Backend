<?php

declare(strict_types=1);

namespace Database\Seeders\DemostracionQa;

use App\Models\Operacion\CashDeposit;
use App\Models\Operacion\CashMovement;
use App\Models\Operacion\StoreCreditMovement;
use App\Models\Order\OrdersSales;
use App\Models\Promotion;
use App\Services\PinDeEntrega;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

// Los pedidos de la app desde que abrió cada negocio, los de hoy y los programados, con su plata.
final class Pedidos
{
    private const EFECTIVO = 1;

    private const TARJETA = 2;

    private const TRANSFERENCIA = 4;

    private const QR = 5;

    private const NEQUI = 6;

    private const FIADO = 7;

    private const CONTRA_ENTREGA = 1;

    private const ANTICIPADO = 2;

    // Minutos desde que entra el pedido hasta que está listo, por oficio.
    private const PREPARACION = ['tienda' => [6, 15], 'drogueria' => [4, 10], 'restaurante' => [14, 30], 'repuestos' => [8, 20]];

    // Qué porcentaje de los pedidos lo recoge el cliente en el local.
    private const RECOGE = ['tienda' => 5, 'drogueria' => 3, 'restaurante' => 12, 'repuestos' => 15];

    // A qué hora llegan los pedidos de cada oficio (peso por hora).
    private const HORAS = [
        'tienda' => [7 => 6, 8 => 8, 9 => 7, 10 => 6, 11 => 6, 12 => 7, 13 => 5, 14 => 4, 15 => 4, 16 => 5, 17 => 7, 18 => 9, 19 => 10, 20 => 8, 21 => 5],
        'drogueria' => [8 => 5, 9 => 7, 10 => 7, 11 => 6, 12 => 5, 13 => 4, 14 => 4, 15 => 5, 16 => 5, 17 => 6, 18 => 7, 19 => 8, 20 => 7, 21 => 5],
        'restaurante' => [7 => 3, 8 => 4, 9 => 3, 10 => 2, 11 => 8, 12 => 14, 13 => 12, 14 => 6, 15 => 2, 16 => 2, 17 => 3, 18 => 6, 19 => 9, 20 => 8, 21 => 4],
        'repuestos' => [8 => 6, 9 => 9, 10 => 10, 11 => 9, 12 => 5, 13 => 4, 14 => 7, 15 => 8, 16 => 8, 17 => 7, 18 => 4],
    ];

    // Lo que está pasando ahora en cada negocio: [estado, minutos desde que entró, medio, extra].
    private const EN_CURSO = [
        'fontalvo' => [[1, 4, 1, null], [1, 38, 6, null], [2, 15, 1, null], [3, 27, 1, null]],
        'sabor' => [[1, 6, 5, null], [2, 18, 1, null], [2, 11, 1, 'recoge'], [3, 31, 6, 'conjunto']],
        'bendicion' => [[1, 3, 1, null], [2, 12, 7, null], [3, 24, 1, null]],
        'santa_rita' => [[2, 8, 6, null], [3, 19, 1, null]],
        'la30' => [[1, 21, 1, null]],
        'farmavida' => [[1, 9, 2, null], [3, 16, 1, null]],
    ];

    // Pedidos programados: [negocio, en cuántos días, hora, minuto, medio].
    private const PROGRAMADOS = [['fontalvo', 1, 9, 0, 6], ['sabor', 1, 12, 30, 1], ['bendicion', 2, 10, 0, 1]];

    // Todas las columnas que se llenan, en un solo orden: así las filas van en lotes grandes.
    private const VACIO = [
        'orderSales_id' => null, 'buyer_id' => null, 'busines_id' => null, 'domiciliary_id' => null,
        'address_id' => null, 'methods_id' => null, 'forms_id' => null, 'total' => 0, 'subtotal' => 0,
        'domicilio' => 0, 'discount' => 0, 'domiciliary_fee' => 0, 'platform_fee' => 0, 'delivery_subsidy' => 0,
        'cash_due' => 0, 'cash_tendered' => null, 'weighing_state' => 0, 'currency' => 'COP', 'sale_date' => null,
        'state' => 1, 'has_review' => 0, 'payment_state' => 'pending', 'pickup' => 0, 'delivery_date' => null,
        'dispatched_at' => null, 'promised_minutes' => null, 'is_scheduled' => 0, 'created_at' => null,
        'updated_at' => null, 'stock_descontado' => 0, 'pin_entrega' => null, 'cash_received' => null,
        'change_given' => null, 'ready_at' => null,
    ];

    public function __construct(private Escenario $e) {}

    public function sembrar(): void
    {
        foreach (array_keys($this->e->negocios) as $clave) {
            if ($this->e->negocios[$clave]['diarios'] > 0) {
                $this->historia($clave);
                $this->enCurso($clave);
                $this->e->lote->guardar();
            }
        }

        $this->programados();
        $this->e->lote->guardar();
    }

    // Las calificaciones y los usos salen de lo sembrado: se ponen al final, sobre filas propias.
    public function calificaciones(): void
    {
        foreach ($this->e->estrellasNegocio as $id => [$suma, $cuantas]) {
            DB::table('business')->where('busines_id', $id)->update(['qualification' => round($suma / $cuantas, 2)]);
        }

        foreach ($this->e->estrellasDomiciliario as $id => [$suma, $cuantas]) {
            DB::table('domiciliary')->where('domiciliary_id', $id)->update(['qualification' => round($suma / $cuantas, 2)]);
        }

        foreach ($this->e->usosDePromocion as $id => $usos) {
            DB::table('promotions')->where('promotion_id', $id)->update(['usos' => $usos]);
        }
    }

    /* ------------------------------ LA HISTORIA ------------------------------ */

    private function historia(string $clave): void
    {
        $n = &$this->e->negocios[$clave];
        $enLaCalle = [];

        for ($dia = $n['primera']; $dia->lte($this->e->hoy); $dia = $dia->addDay()) {
            $atras = (int) round($dia->diffInDays($this->e->hoy, true));
            $edad = (int) round($n['primera']->diffInDays($dia, true));

            foreach ($this->horas($n, $dia, $atras, $edad) as $cuando) {
                $p = $this->pasado($n, $cuando, $atras);

                if ($p !== null && $p['efectivo_con'] !== null) {
                    $enLaCalle[$p['efectivo_con']] = ($enLaCalle[$p['efectivo_con']] ?? 0) + $p['total'];
                }
            }

            $this->cierre($n, $dia, $atras, $enLaCalle);
            $this->abonos($n, $dia, $atras);
        }
    }

    // Cuántos pedidos y a qué horas: arranca despacio, crece y vende más el fin de semana.
    private function horas(array $n, CarbonImmutable $dia, int $atras, int $edad): array
    {
        $base = $n['diarios'] * min(1.0, 0.25 + 0.75 * $edad / 75) * (0.78 + 0.22 * max(0.0, 1 - $atras / 330));
        $base *= $this->deLaSemana($n['oficio'], $dia);
        $base *= in_array($dia->day, [1, 15, 16, 30, 31], true) ? 1.15 : 1.0;
        $cuantos = (int) floor($base) + (mt_rand(0, 999) < ($base - floor($base)) * 1000 ? 1 : 0);
        $cuantos = max(0, $cuantos + (int) Azar::elegir([-1 => 15, 0 => 70, 1 => 15]));
        $horas = [];

        for ($k = 0; $k < $cuantos; $k++) {
            $horas[] = $dia->setTime((int) Azar::elegir(self::HORAS[$n['oficio']]), mt_rand(0, 59), mt_rand(0, 59));
        }

        // Hoy solo los que ya tuvieron tiempo de entregarse: los recientes van en curso.
        if ($atras === 0) {
            $horas = array_filter($horas, fn ($h) => $h->lt($this->e->ahora->subMinutes(110)));
        }

        usort($horas, fn ($a, $b) => $a <=> $b);

        return array_values($horas);
    }

    private function deLaSemana(string $oficio, CarbonImmutable $dia): float
    {
        return match (true) {
            $oficio === 'repuestos' => $dia->isSunday() ? 0.4 : ($dia->isSaturday() ? 1.2 : 1.0),
            $oficio === 'drogueria' => $dia->isSunday() ? 0.85 : 1.0,
            default => $dia->isSaturday() ? 1.3 : ($dia->isSunday() ? 1.2 : 1.0),
        };
    }

    /** @return array{total: float, efectivo_con: ?int}|null */
    private function pasado(array &$n, CarbonImmutable $cuando, int $atras): ?array
    {
        $i = $this->cliente($n, $cuando);

        if ($i === null) {
            return null;
        }

        $c = $this->e->compradores[$i];
        $viejo = min(1.0, $atras / 300);
        $cancelado = Azar::si((int) round(4 + 4 * $viejo));
        $domi = Azar::si(self::RECOGE[$n['oficio']]) ? null : $this->domiciliario($n, $cuando);
        $armado = $this->armar($n, $cuando, ! $cancelado);
        $km = Azar::km($n['lat'], $n['lng'], $c['lat'], $c['lng']);
        $domicilio = $domi === null ? 0.0 : (float) min(8000, 4000 + 1000 * (int) round($km));
        $total = $armado['subtotal'] - $armado['descuento'] + $domicilio;
        $metodo = $this->medio($n, $i, $total, $cuando, $cancelado);

        $listo = $cuando->addMinutes(mt_rand(...self::PREPARACION[$n['oficio']]));
        $camino = Azar::si((int) round(4 + 6 * $viejo))
            ? mt_rand($n['promesa'] + 2, $n['promesa'] + 18)
            : min($n['promesa'] - 2, mt_rand(5, 12) + (int) round($km * 2.2));
        $despacho = $domi === null ? null : $listo->addMinutes(mt_rand(1, 6));
        $entrega = ($despacho?->addMinutes($camino) ?? $listo->addMinutes(mt_rand(4, 25)))->min($this->e->ahora->subMinutes(2));

        $id = $this->e->lote->id('orderssales', 'orderSales_id');
        $efectivo = $metodo === self::EFECTIVO;
        $paga = $efectivo ? $this->conCuantoPaga($total) : null;
        $resena = ! $cancelado && $entrega->lt($this->e->ahora->subHour()) && Azar::si(11);
        $entregado = ! $cancelado;

        $this->pedido([
            'orderSales_id' => $id, 'buyer_id' => $c['buyer_id'], 'busines_id' => $n['id'],
            'domiciliary_id' => $entregado ? $domi : null, 'address_id' => $domi === null ? null : $c['address_id'],
            'methods_id' => $metodo, 'state' => $entregado ? OrdersSales::ENTREGADO : OrdersSales::CANCELADO,
            'payment_state' => $entregado ? 'paid' : 'cancelled', 'has_review' => $resena ? 1 : 0,
            'pickup' => $domi === null ? 1 : 0, 'pin_entrega' => $domi === null ? PinDeEntrega::generar() : null,
            'cash_due' => $efectivo && $entregado && $domi ? $total : 0, 'cash_tendered' => $paga,
            'cash_received' => $efectivo && $entregado ? $paga : null,
            'change_given' => $efectivo && $entregado ? $paga - $total : null,
            'stock_descontado' => $entregado ? 1 : 0, 'ready_at' => $entregado ? $listo : null,
            'dispatched_at' => $entregado ? $despacho : null, 'delivery_date' => $entregado ? $entrega : null,
            'updated_at' => $entregado ? $entrega : $cuando->addMinutes(mt_rand(4, 15)),
        ], $n, $armado, $domicilio, $cuando);

        if ($cancelado) {
            return ['total' => $total, 'efectivo_con' => null];
        }

        $this->alEntregar($n, $id, $i, $metodo, $armado, $domicilio, $total, $cuando, $entrega, $domi);

        if ($resena) {
            $this->resena($n, $c['buyer_id'], $domi, $entrega);
        }

        if ($c['residente'] && $domi && $entrega->gte($this->e->hoy->subDays(30))) {
            $this->e->alConjunto[] = $this->paraLaPorteria($n, $id, $c, $domi, $total, $metodo, $armado, $entrega, false);
        }

        return ['total' => $total, 'efectivo_con' => $efectivo ? $domi : null];
    }

    /** @param array<string, mixed> $valores */
    private function pedido(array $valores, array $n, array $armado, float $domicilio, CarbonImmutable $cuando): void
    {
        $metodo = (int) $valores['methods_id'];
        $total = $armado['subtotal'] - $armado['descuento'] + $domicilio;

        $this->e->lote->poner('orderssales', array_merge(self::VACIO, [
            'forms_id' => in_array($metodo, [self::EFECTIVO, self::FIADO], true) ? self::CONTRA_ENTREGA : self::ANTICIPADO,
            'subtotal' => $armado['subtotal'], 'domicilio' => $domicilio, 'discount' => $armado['descuento'],
            'domiciliary_fee' => round($domicilio * $this->e->reparto), 'total' => $total,
            'platform_fee' => round($armado['subtotal'] * $this->e->comision, 2), 'sale_date' => $cuando,
            'promised_minutes' => $n['promesa'], 'created_at' => $cuando,
        ], $valores));

        foreach ($armado['renglones'] as $r) {
            $this->e->lote->poner('orderssales_detail', $r + ['orderSales_id' => $valores['orderSales_id']]);
        }
    }

    // El cobro, el recaudo del domiciliario, el consumo del fiado y la promoción aplicada.
    private function alEntregar(
        array &$n,
        int $id,
        int $i,
        int $metodo,
        array $armado,
        float $domicilio,
        float $total,
        CarbonImmutable $cuando,
        CarbonImmutable $entrega,
        ?int $domi,
    ): void {
        if ($armado['promo'] !== null) {
            $this->e->lote->poner('orders_promotions', ['orderSales_id' => $id, 'promotion_id' => $armado['promo'], 'state' => 1]);
        }

        if ($metodo === self::FIADO) {
            $this->consumo($n, $i, $id, $total, $cuando);

            return;
        }

        $this->pago($id, $metodo, $armado, $domicilio, $total, $metodo === self::EFECTIVO ? $entrega : $cuando);

        if ($metodo === self::EFECTIVO && $domi) {
            $this->e->lote->poner('cash_movements', [
                'domiciliary_id' => $domi, 'type' => CashMovement::RECAUDO, 'amount' => $total, 'order_id' => $id,
                'deposit_id' => null, 'notes' => null, 'created_by' => null, 'created_at' => $entrega, 'updated_at' => $entrega,
            ]);
        }
    }

    private function pago(int $id, int $metodo, array $armado, float $domicilio, float $total, CarbonImmutable $cuando): void
    {
        $efectivo = $metodo === self::EFECTIVO;

        $this->e->lote->poner('payments', [
            'orderSales_id' => $id, 'methods_id' => $metodo, 'forms_id' => $efectivo ? self::CONTRA_ENTREGA : self::ANTICIPADO,
            'provider' => $efectivo ? 'cash' : 'bold', 'provider_payment_id' => $efectivo ? null : 'demoqa-'.$id,
            'amount' => $total, 'subtotal' => $armado['subtotal'], 'total' => $total, 'domicilio' => $domicilio,
            'domiciliary_fee' => round($domicilio * $this->e->reparto), 'valor_promocion' => $armado['descuento'],
            'payment_status' => 1, 'status' => 'approved', 'payment_date' => $cuando, 'state' => 1,
            'created_at' => $cuando, 'updated_at' => $cuando,
        ]);
    }

    private function consumo(array &$n, int $i, int $pedido, float $total, CarbonImmutable $cuando): void
    {
        $n['creditos'][$i]['usado'] += $total;

        $this->e->lote->poner('store_credit_movements', [
            'store_credit_id' => $n['creditos'][$i]['id'], 'type' => StoreCreditMovement::CONSUMO, 'amount' => $total,
            'order_id' => $pedido, 'notes' => null, 'created_by' => null, 'created_at' => $cuando, 'updated_at' => $cuando,
        ]);
    }

    private function medio(array $n, int $i, float $total, CarbonImmutable $cuando, bool $cancelado): int
    {
        $credito = $n['creditos'][$i] ?? null;

        if (! $cancelado && $credito && $cuando->gte($credito['desde']) && Azar::si(40) && $total <= $credito['cupo'] - $credito['usado']) {
            return self::FIADO;
        }

        return (int) Azar::elegir([self::EFECTIVO => 44, self::NEQUI => 24, self::TARJETA => 10, self::QR => 9, self::TRANSFERENCIA => 6]);
    }

    // Un cliente de la clientela que ya estaba registrado y no se ha ido, según cuánto compra.
    private function cliente(array $n, CarbonImmutable $cuando): ?int
    {
        $pesos = [];

        foreach ($n['clientela'] as $c) {
            $p = $this->e->compradores[$c['i']];

            if ($p['alta']->gt($cuando->subHour())) {
                break;
            }

            if ($p['baja'] === null || $p['baja']->gt($cuando)) {
                $pesos[$c['i']] = $c['peso'];
            }
        }

        return $pesos === [] ? null : (int) Azar::elegir($pesos);
    }

    private function domiciliario(array $n, CarbonImmutable $cuando): ?int
    {
        $activos = array_values(array_filter($n['flota'], fn ($d) => $d['alta']->lte($cuando)));

        return $activos === [] ? null : (int) Azar::uno($activos)['id'];
    }

    /* ------------------------------ LOS RENGLONES ------------------------------ */

    /** @return array{renglones: list<array>, subtotal: float, descuento: float, promo: ?int} */
    private function armar(array $n, CarbonImmutable $cuando, bool $conPromo): array
    {
        $cantidades = [];
        $porId = array_column($n['surtido'], null, 'id');
        $sumar = function (int $id, int $cuantos) use (&$cantidades) {
            $cantidades[$id] = ($cantidades[$id] ?? 0) + $cuantos;
        };

        if ($n['oficio'] === 'restaurante') {
            $momento = $cuando->hour < 10 ? 'desayuno' : ($cuando->hour < 16 ? 'almuerzo' : 'cena');

            for ($k = (int) Azar::elegir([1 => 48, 2 => 30, 3 => 12, 4 => 10]); $k > 0; $k--) {
                foreach ([$momento => 100, 'bebida' => 70, 'postre' => 14, 'adicional' => 12] as $grupo => $probabilidad) {
                    $plato = Azar::si($probabilidad) ? $this->delGrupo($n['surtido'], $grupo) : null;

                    if ($plato !== null) {
                        $sumar($plato, 1);
                    }
                }
            }
        } else {
            $lineas = match ($n['oficio']) {
                'tienda' => Azar::elegir([1 => 12, 2 => 22, 3 => 25, 4 => 18, 5 => 13, 6 => 10]),
                'repuestos' => Azar::elegir([1 => 70, 2 => 30]),
                default => Azar::elegir([1 => 45, 2 => 33, 3 => 16, 4 => 6]),
            };
            $pesos = array_column($n['surtido'], 'peso', 'id');

            for ($k = (int) $lineas; $k > 0; $k--) {
                $sumar((int) Azar::elegir($pesos), (int) Azar::elegir($n['oficio'] === 'tienda' ? [1 => 60, 2 => 28, 3 => 12] : [1 => 80, 2 => 20]));
            }
        }

        [$descuento, $promo] = $conPromo ? $this->promocion($n, $cuando, $cantidades, $porId) : [0.0, null];
        $renglones = [];
        $subtotal = 0.0;

        foreach ($cantidades as $id => $cuantos) {
            $p = $porId[$id];
            $subtotal += $p['precio'] * $cuantos;
            $renglones[] = [
                'product_id' => $id, 'amount' => $cuantos, 'unit_price' => $p['precio'], 'sale_unit' => 'unidad',
                'line_total' => $p['precio'] * $cuantos, 'unit_cost' => $p['costo'],
            ];
        }

        return ['renglones' => $renglones, 'subtotal' => $subtotal, 'descuento' => (float) $descuento, 'promo' => $promo];
    }

    /** @return array{0: float, 1: ?int} */
    private function promocion(array $n, CarbonImmutable $cuando, array &$cantidades, array $porId): array
    {
        foreach ($n['promos'] as $promo) {
            $usos = $this->e->usosDePromocion[$promo['id']];

            if (! $cuando->between($promo['desde'], $promo['hasta']) || ($promo['max'] !== null && $usos >= $promo['max']) || ! Azar::si(35)) {
                continue;
            }

            $pid = (int) Azar::uno($promo['productos']);
            $precio = $porId[$pid]['precio'];

            if ($promo['tipo'] === Promotion::NXM) {
                $cantidades[$pid] = max($cantidades[$pid] ?? 0, (int) $promo['lleva']);
                $descuento = intdiv($cantidades[$pid], (int) $promo['lleva']) * ((int) $promo['lleva'] - (int) $promo['paga']) * $precio;
            } else {
                $cantidades[$pid] ??= 1;
                $descuento = round($precio * $cantidades[$pid] * (float) $promo['pct']);
            }

            $this->e->usosDePromocion[$promo['id']]++;

            return [(float) $descuento, (int) $promo['id']];
        }

        return [0.0, null];
    }

    private function delGrupo(array $surtido, string $grupo): ?int
    {
        $pesos = [];

        foreach ($surtido as $p) {
            if ($p['grupo'] === $grupo) {
                $pesos[$p['id']] = $p['peso'];
            }
        }

        return $pesos === [] ? null : (int) Azar::elegir($pesos);
    }

    private function conCuantoPaga(float $total): float
    {
        $tiro = mt_rand(1, 100);

        return match (true) {
            $tiro <= 30 => $total,
            $tiro <= 70 || $total > 50000 => ceil($total / 10000) * 10000,
            default => 50000.0,
        };
    }

    /* ------------------------------ EL EFECTIVO Y EL FIADO ------------------------------ */

    // Se entrega lo cobrado: a diario 45 días, antes los domingos; ayer uno solo y hoy nadie.
    private function cierre(array $n, CarbonImmutable $dia, int $atras, array &$enLaCalle): void
    {
        if ($atras === 0 || ($atras > 45 && ! $dia->isSunday())) {
            return;
        }

        foreach ($enLaCalle as $domi => $debe) {
            if ($debe <= 0 || ($atras === 1 && $domi !== array_key_first($enLaCalle))) {
                continue;
            }

            $parcial = $atras > 1 && Azar::si(15);
            $monto = $parcial ? floor($debe * 0.6 / 1000) * 1000 : round($debe, 2);
            $cuando = $dia->setTime(23, mt_rand(20, 55));

            if ($monto <= 0) {
                continue;
            }

            $id = $this->e->lote->id('cash_deposits', 'id');
            $nota = $parcial ? 'Abonó, se quedó con un billete grande' : 'Entregó todo al cerrar';

            $this->e->lote->poner('cash_deposits', [
                'id' => $id, 'domiciliary_id' => $domi, 'canal' => CashDeposit::MOSTRADOR, 'busines_id' => $n['id'],
                'amount' => $monto, 'reference' => 'Entregado en el mostrador', 'deposited_at' => $cuando,
                'state' => CashDeposit::CONFIRMADA, 'confirmed_by' => $n['dueno_user'], 'confirmed_at' => $cuando,
                'notes' => $nota, 'created_at' => $cuando, 'updated_at' => $cuando,
            ]);
            $this->e->lote->poner('cash_movements', [
                'domiciliary_id' => $domi, 'type' => CashMovement::CONSIGNACION, 'amount' => -$monto, 'order_id' => null,
                'deposit_id' => $id, 'notes' => null, 'created_by' => $n['dueno_user'], 'created_at' => $cuando, 'updated_at' => $cuando,
            ]);

            $enLaCalle[$domi] = round($debe - $monto, 2);
        }
    }

    // En quincena se abona el fiado; en la última, uno de cada tres no pagó y hoy debe.
    private function abonos(array &$n, CarbonImmutable $dia, int $atras): void
    {
        if (! in_array($dia->day, [15, 30], true) && ! ($dia->month === 2 && $dia->isLastOfMonth())) {
            return;
        }

        $k = 0;

        foreach ($n['creditos'] as $i => $credito) {
            $k++;
            $usado = round($credito['usado'], 2);

            if ($usado <= 0 || ($atras <= 15 && $k % 3 === 1)) {
                continue;
            }

            $monto = $k % 3 === 2 ? floor($usado * 0.5 / 1000) * 1000 : $usado;
            $cuando = $dia->setTime(18, mt_rand(0, 59));

            if ($monto <= 0 || $cuando->gt($this->e->ahora)) {
                continue;
            }

            $n['creditos'][$i]['usado'] = $usado - $monto;
            $this->e->lote->poner('store_credit_movements', [
                'store_credit_id' => $credito['id'], 'type' => StoreCreditMovement::ABONO, 'amount' => -$monto,
                'order_id' => null, 'notes' => 'Abono de la quincena', 'created_by' => $n['dueno_user'],
                'created_at' => $cuando, 'updated_at' => $cuando,
            ]);
        }
    }

    /* ------------------------------ LAS RESEÑAS ------------------------------ */

    private function resena(array $n, int $buyerId, ?int $domi, CarbonImmutable $entrega): void
    {
        $cuando = $entrega->addMinutes(mt_rand(10, 300))->min($this->e->ahora->subMinutes(5));
        $estrellas = (int) Azar::elegir([5 => 58, 4 => 25, 3 => 9, 2 => 5, 1 => 3]);
        $texto = Azar::si(20) ? null : Azar::uno(Guion::RESENAS[$n['oficio']][$estrellas]);

        $this->e->lote->poner('business_reviews', [
            'busines_id' => $n['id'], 'buyer_id' => $buyerId, 'qualification' => $estrellas, 'comment' => $texto,
            'state' => 1, 'created_at' => $cuando, 'updated_at' => $cuando,
        ]);
        $this->e->estrellasNegocio[$n['id']] = [($this->e->estrellasNegocio[$n['id']][0] ?? 0) + $estrellas, ($this->e->estrellasNegocio[$n['id']][1] ?? 0) + 1];

        if ($domi && Azar::si(50)) {
            $suyas = (int) Azar::elegir([5 => 62, 4 => 24, 3 => 8, 2 => 4, 1 => 2]);
            $this->e->lote->poner('domiciliary_reviews', [
                'domiciliary_id' => $domi, 'buyer_id' => $buyerId, 'qualification' => $suyas,
                'comment' => Azar::uno(Guion::RESENAS_DOMICILIARIO[$suyas]), 'state' => 1,
                'created_at' => $cuando, 'updated_at' => $cuando,
            ]);
            $this->e->estrellasDomiciliario[$domi] = [($this->e->estrellasDomiciliario[$domi][0] ?? 0) + $suyas, ($this->e->estrellasDomiciliario[$domi][1] ?? 0) + 1];
        }
    }

    /* ------------------------------ HOY ------------------------------ */

    private function enCurso(string $clave): void
    {
        $n = &$this->e->negocios[$clave];
        $residentes = array_keys(array_filter($this->e->compradores, fn ($c) => $c['residente']));

        foreach (self::EN_CURSO[$clave] ?? [] as [$estado, $hace, $metodo, $extra]) {
            $cuando = $this->e->ahora->subMinutes($hace);
            $i = $extra === 'conjunto' ? (int) Azar::uno($residentes) : $this->cliente($n, $cuando);

            if ($i === null) {
                continue;
            }

            if ($metodo === self::FIADO) {
                $i = array_key_first($n['creditos']) ?? $i;
                $metodo = isset($n['creditos'][$i]) ? self::FIADO : self::EFECTIVO;
            }

            $this->unoEnCurso($n, $i, $estado, $cuando, $metodo, $extra);
        }
    }

    private function unoEnCurso(array &$n, int $i, int $estado, CarbonImmutable $cuando, int $metodo, ?string $extra): void
    {
        $c = $this->e->compradores[$i];
        $recoge = $extra === 'recoge';
        $domi = $estado === OrdersSales::EN_CAMINO ? $this->domiciliario($n, $cuando) : null;
        $armado = $this->armar($n, $cuando, false);
        $domicilio = $recoge ? 0.0 : (float) min(8000, 4000 + 1000 * (int) round(Azar::km($n['lat'], $n['lng'], $c['lat'], $c['lng'])));
        $total = $armado['subtotal'] + $domicilio;
        $hace = (int) $cuando->diffInMinutes($this->e->ahora, true);
        $listo = $estado === OrdersSales::EN_CAMINO ? $cuando->addMinutes((int) round($hace * 0.45)) : null;
        $despacho = $listo?->addMinutes(2);
        $id = $this->e->lote->id('orderssales', 'orderSales_id');

        $this->pedido([
            'orderSales_id' => $id, 'buyer_id' => $c['buyer_id'], 'busines_id' => $n['id'], 'domiciliary_id' => $domi,
            'address_id' => $recoge ? null : $c['address_id'], 'methods_id' => $metodo, 'state' => $estado,
            'payment_state' => match ($metodo) {
                self::EFECTIVO => 'pending_cash', self::FIADO => OrdersSales::A_CREDITO, default => 'paid',
            },
            'pickup' => $recoge ? 1 : 0, 'pin_entrega' => $recoge ? PinDeEntrega::generar() : null,
            'cash_tendered' => $metodo === self::EFECTIVO ? $this->conCuantoPaga($total) : null,
            'stock_descontado' => $estado === OrdersSales::NUEVO ? 0 : 1, 'ready_at' => $listo,
            'dispatched_at' => $despacho, 'updated_at' => $despacho ?? $cuando,
        ], $n, $armado, $domicilio, $cuando);

        if ($metodo === self::FIADO) {
            $this->consumo($n, $i, $id, $total, $cuando);
        } elseif ($metodo !== self::EFECTIVO) {
            $this->pago($id, $metodo, $armado, $domicilio, $total, $cuando);
        }

        if ($domi && $despacho) {
            $this->rastro($n, $id, $domi, $despacho, $c);

            if ($extra === 'conjunto') {
                $this->e->alConjunto[] = $this->paraLaPorteria($n, $id, $c, $domi, $total, $metodo, $armado, $this->e->ahora, true);
            }
        }
    }

    private function programados(): void
    {
        foreach (self::PROGRAMADOS as [$clave, $dias, $hora, $minuto, $metodo]) {
            $n = &$this->e->negocios[$clave];
            $cuando = $this->e->ahora->subMinutes(mt_rand(20, 240));
            $i = $this->cliente($n, $cuando);

            if ($i === null) {
                continue;
            }

            $c = $this->e->compradores[$i];
            $armado = $this->armar($n, $this->e->hoy->addDays($dias)->setTime($hora, $minuto), false);
            $id = $this->e->lote->id('orderssales', 'orderSales_id');

            $this->pedido([
                'orderSales_id' => $id, 'buyer_id' => $c['buyer_id'], 'busines_id' => $n['id'],
                'address_id' => $c['address_id'], 'methods_id' => $metodo, 'state' => OrdersSales::NUEVO,
                'payment_state' => $metodo === self::EFECTIVO ? 'pending_cash' : 'paid', 'is_scheduled' => 1,
                'delivery_date' => $this->e->hoy->addDays($dias)->setTime($hora, $minuto), 'updated_at' => $cuando,
            ], $n, $armado, $this->e->tarifa, $cuando);

            if ($metodo !== self::EFECTIVO) {
                $this->pago($id, $metodo, $armado, $this->e->tarifa, $armado['subtotal'] + $this->e->tarifa, $cuando);
            }

            unset($n);
        }
    }

    // El rastro de la moto hacia la casa, un punto cada dos minutos; el último, de hace uno.
    private function rastro(array $n, int $pedido, int $domi, CarbonImmutable $salio, array $c): void
    {
        $puntos = max(2, intdiv((int) $salio->diffInMinutes($this->e->ahora, true), 2));

        for ($k = 1; $k <= $puntos; $k++) {
            $avance = min(0.85, $k / $puntos * 0.85);
            $cuando = $k === $puntos ? $this->e->ahora->subMinute() : $salio->addMinutes($k * 2);

            $this->e->lote->poner('order_geolocation', [
                'domiciliary_id' => $domi, 'orderSales_id' => $pedido,
                'latitude' => round($n['lat'] + ($c['lat'] - $n['lat']) * $avance + mt_rand(-6, 6) / 100000, 6),
                'longitude' => round($n['lng'] + ($c['lng'] - $n['lng']) * $avance + mt_rand(-6, 6) / 100000, 6),
                'state' => 1, 'created_at' => $cuando, 'updated_at' => $cuando,
            ]);
        }
    }

    // El pedido como lo congela la portería en `complex_entries.orders` (ver AccesoAlConjunto).
    private function paraLaPorteria(
        array $n,
        int $id,
        array $c,
        int $domi,
        float $total,
        int $metodo,
        array $armado,
        CarbonImmutable $entrega,
        bool $adentro,
    ): array {
        $nombres = array_column($n['surtido'], 'nombre', 'id');
        $items = array_map(fn ($r) => [
            'name' => $nombres[$r['product_id']], 'amount' => $r['amount'], 'unit_price' => $r['unit_price'],
            'line_total' => null, 'sale_unit' => 'unidad', 'qty_requested' => null, 'qty_final' => null,
        ], $armado['renglones']);

        return [
            'domi' => $domi, 'entrega' => $entrega, 'adentro' => $adentro,
            'pedido' => [
                'orderSales_id' => $id, 'total' => number_format($total, 2, '.', ''), 'methods_id' => $metodo,
                'tower' => $c['torre'], 'apartment' => $c['apto'], 'business_name' => $n['nombre'], 'business_logo' => null,
                'items' => $items, 'items_count' => array_sum(array_column($items, 'amount')),
            ],
        ];
    }
}
