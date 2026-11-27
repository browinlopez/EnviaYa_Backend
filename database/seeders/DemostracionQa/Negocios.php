<?php

declare(strict_types=1);

namespace Database\Seeders\DemostracionQa;

use App\Models\Promotion;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

// Los negocios con su dueño, su flota, su surtido, sus gastos, sus promociones y su clientela.
final class Negocios
{
    // Cuántos productos del catálogo general lleva cada tienda.
    private const DEL_CATALOGO = 80;

    // Qué parte del precio es costo, en centésimas: [mín, máx].
    private const COSTO = ['tienda' => [70, 82], 'drogueria' => [58, 72], 'restaurante' => [32, 45], 'repuestos' => [55, 70]];

    // Tope de fiado del negocio y cupo por cliente, según el oficio.
    private const TOPE = ['tienda' => 1500000, 'restaurante' => 800000];

    private const CUPO = ['tienda' => [150000, 250000], 'drogueria' => [50000, 80000], 'restaurante' => [100000, 150000], 'repuestos' => [200000, 200000]];

    private const CON_CUPO = ['tienda' => 3, 'drogueria' => 2, 'restaurante' => 3, 'repuestos' => 1];

    private const DE_UNA_VEZ = [
        'tienda' => ['Reparación de la nevera de las gaseosas', 180000],
        'drogueria' => ['Nevera para medicamentos refrigerados', 950000],
        'restaurante' => ['Mantenimiento de la estufa industrial', 260000],
        'repuestos' => ['Compresor de aire', 680000],
    ];

    public function __construct(private Escenario $e) {}

    public function sembrar(): void
    {
        $catalogo = $this->catalogoGeneral();
        $categoria = DB::table('category')->where('business_category_id', 2)->orderBy('category_id')->value('category_id');

        foreach (Guion::NEGOCIOS as $clave => $g) {
            $n = $this->ficha($clave, $g);
            $n['surtido'] = $this->surtido($n, $catalogo, $n['oficio'] === 'drogueria' ? $categoria : null);
            $this->gastos($n);
            $n['clientela'] = $this->clientela($n);
            $n['promos'] = $this->promociones($n);
            $n['creditos'] = $this->fiados($n);
            $this->e->negocios[$clave] = $n;
        }
    }

    /** @return array<string, mixed> */
    private function ficha(string $clave, array $g): array
    {
        $id = $this->e->lote->id('business', 'busines_id');
        $abre = $this->e->haceDias($g['alta'], 9, mt_rand(0, 50));
        $dueno = $this->e->duenos[$g['dueno']];

        $this->e->lote->poner('business', [
            'busines_id' => $id, 'name' => $g['nombre'], 'phone' => $g['telefono'], 'address' => $g['direccion'],
            'qualification' => 0, 'razonSocial_DCD' => Guion::DUENOS[$g['dueno']][0], 'NIT' => $g['nit'],
            'description' => $g['descripcion'], 'latitude' => $g['lat'], 'longitude' => $g['lng'],
            'municipality_id' => $this->e->municipios[$g['municipio']], 'type' => $this->e->tipos[$g['oficio']],
            'credit_debt_cap' => self::TOPE[$g['oficio']] ?? null, 'state' => 1, 'accepting_orders' => 1,
            'created_at' => $abre, 'updated_at' => $abre,
        ]);
        $this->e->lote->poner('owner_busines', ['owner_id' => $dueno['owner_id'], 'busines_id' => $id, 'state' => 1]);

        $flota = [];

        foreach ($this->e->domiciliarios as $d) {
            if (in_array($clave, $d['negocios'], true)) {
                $this->e->lote->poner('business_domiciliary', ['busines_id' => $id, 'domiciliary_id' => $d['id'], 'state' => 1]);
                $flota[] = ['id' => $d['id'], 'alta' => $d['alta']];
            }
        }

        return $g + [
            'clave' => $clave, 'id' => $id, 'abre' => $abre, 'dueno_user' => $dueno['user_id'], 'flota' => $flota,
            'primera' => $abre->addDays($g['arranque'])->startOfDay(),
        ];
    }

    // El catálogo general con el precio al que ya lo venden en la plataforma (si lo hay).
    private function catalogoGeneral(): array
    {
        return DB::table('products as p')
            ->join('products_business as pb', 'pb.products_id', '=', 'p.products_id')
            ->where('p.state', 1)
            ->where('p.en_catalogo_general', 1)
            ->where('pb.price', '>=', 1000)
            ->groupBy('p.products_id', 'p.name')
            ->orderBy('p.products_id')
            ->limit(400)
            ->get(['p.products_id', 'p.name', DB::raw('AVG(pb.price) as precio')])
            ->map(fn ($f) => ['id' => (int) $f->products_id, 'nombre' => (string) $f->name, 'precio' => (float) $f->precio])
            ->all();
    }

    /** @return list<array<string, mixed>> */
    private function surtido(array $n, array $catalogo, ?int $categoria): array
    {
        $usaCatalogo = $n['oficio'] === 'tienda' && count($catalogo) >= 40;
        $filas = $usaCatalogo
            ? array_slice(Azar::barajar($catalogo), 0, self::DEL_CATALOGO)
            : array_map(fn ($p) => ['id' => null, 'nombre' => $p[0], 'precio' => (float) $p[1], 'grupo' => $p[2], 'peso' => $p[3]], Guion::SURTIDOS[$n['oficio']]);

        $surtido = [];

        foreach ($filas as $i => $f) {
            $productoId = $f['id'] ?? $this->productoPropio($n, $f['nombre'], $categoria);
            $precio = $usaCatalogo ? max(500, round($f['precio'] * mt_rand(94, 108) / 100 / 100) * 100) : $f['precio'];
            [$min, $max] = self::COSTO[$n['oficio']];
            $costo = $i % 7 === 5 ? null : round($precio * mt_rand($min, $max) / 100 / 50) * 50;
            $pb = $this->e->lote->id('products_business', 'busines_products_id');

            $this->e->lote->poner('products_business', [
                'busines_products_id' => $pb, 'busines_id' => $n['id'], 'products_id' => $productoId,
                'price' => $precio, 'sale_unit' => 'unidad', 'amount' => mt_rand(12, 90), 'stock_weight' => 0,
                'por_valor' => 0, 'qualification' => 0, 'created_at' => $n['abre'], 'updated_at' => $n['abre'],
                'costo' => $costo,
            ]);

            $surtido[] = [
                'id' => $productoId, 'nombre' => $f['nombre'], 'precio' => (float) $precio,
                'costo' => $costo === null ? null : (float) $costo, 'grupo' => $f['grupo'] ?? 'general',
                'peso' => $f['peso'] ?? max(1, (int) round(1000 / (($i + 1) ** 0.85))),
            ];
        }

        return $surtido;
    }

    // Producto propio del negocio, ya revisado: no entra al catálogo general ni a la bandeja.
    private function productoPropio(array $n, string $nombre, ?int $categoria): int
    {
        $id = $this->e->lote->id('products', 'products_id');

        $this->e->lote->poner('products', [
            'products_id' => $id, 'name' => $nombre, 'category_id' => $categoria, 'state' => 1,
            'origen' => 'tendero', 'busines_id_propietario' => $n['id'], 'en_catalogo_general' => 0,
            'revisado_at' => $n['abre'], 'created_at' => $n['abre'], 'updated_at' => $n['abre'],
        ]);

        return $id;
    }

    private function gastos(array $n): void
    {
        $desde = $n['abre']->startOfMonth();
        $gastos = Guion::GASTOS[$n['oficio']];

        if ($n['alta'] > 25) {
            [$nombre, $monto] = self::DE_UNA_VEZ[$n['oficio']];
            $gastos[] = ['otro', $nombre, $monto, 'una_vez', $this->e->hoy->subDays(mt_rand(8, 20))];
        }

        foreach ($gastos as $g) {
            $id = $this->e->lote->id('gastos_del_negocio', 'gasto_id');
            $inicio = $g[4] ?? $desde;

            $this->e->lote->poner('gastos_del_negocio', [
                'gasto_id' => $id, 'busines_id' => $n['id'], 'tipo' => $g[0], 'nombre' => $g[1], 'monto' => $g[2],
                'frecuencia' => $g[3], 'desde' => $inicio->toDateString(), 'hasta' => null,
                'created_at' => $inicio, 'updated_at' => $inicio,
            ]);

            // La luz cambia mes a mes: los dos últimos meses con su valor real.
            if ($g[0] === 'luz') {
                foreach ([2, 1] as $atras) {
                    $periodo = $this->e->hoy->subMonthsNoOverflow($atras);

                    if ($periodo->gte($desde)) {
                        $this->e->lote->poner('gastos_del_mes', [
                            'gasto_id' => $id, 'periodo' => $periodo->format('Y-m'),
                            'monto' => round($g[2] * mt_rand(90, 115) / 100 / 1000) * 1000,
                            'created_at' => $this->e->ahora, 'updated_at' => $this->e->ahora,
                        ]);
                    }
                }
            }
        }
    }

    // Quién le compra y cuánto, por fecha de alta; los doce que más compran quedan afiliados.
    private function clientela(array $n): array
    {
        $lista = [];

        foreach ($this->e->compradores as $i => $c) {
            $afinidad = $n['zonas'][$c['zona']] ?? 0.0;

            if ($afinidad > 0 && $c['peso'] > 0) {
                $lista[] = ['i' => $i, 'peso' => $c['peso'] * $afinidad * mt_rand(60, 100) / 100];
            }
        }

        $porPeso = $lista;
        usort($porPeso, fn ($a, $b) => $b['peso'] <=> $a['peso']);

        foreach (array_slice($porPeso, 0, 12) as $c) {
            $this->e->lote->poner('business_user_affiliations', [
                'user_id' => $this->e->compradores[$c['i']]['user_id'], 'busines_id' => $n['id'],
            ]);
        }

        usort($lista, fn ($a, $b) => $this->e->compradores[$a['i']]['alta'] <=> $this->e->compradores[$b['i']]['alta']);

        return $lista;
    }

    /** @return list<array<string, mixed>> */
    private function promociones(array $n): array
    {
        $vigentes = [];

        foreach (Guion::PROMOCIONES[$n['oficio']] as [$texto, $tipo, $pct, $lleva, $paga, $buscar, $desde, $hasta, $estado]) {
            $inicio = $this->e->hoy->addDays($desde);
            $fin = $this->e->hoy->addDays($hasta)->endOfDay();
            $productos = $this->buscar($n['surtido'], $buscar);
            $enviada = $estado === Promotion::ENVIADA;

            if (($enviada && $fin->lt($n['primera'])) || ($tipo !== Promotion::SIN_DESCUENTO && $productos === [])) {
                continue;
            }

            $id = $this->e->lote->id('promotions', 'promotion_id');
            $this->e->lote->poner('promotions', [
                'promotion_id' => $id, 'busines_id' => $n['id'], 'description' => $texto, 'tipo' => $tipo,
                'percentage_discount' => $pct, 'lleva' => $lleva, 'paga' => $paga, 'start_date' => $inicio,
                'end_date' => $fin, 'max_usos' => $tipo === Promotion::NXM ? 40 : null, 'usos' => 0,
                'sent_at' => $enviada ? $inicio->setTime(8, 15) : null, 'recipients_count' => $enviada ? 12 : 0,
                'created_by' => $n['dueno_user'], 'state' => $estado, 'created_at' => $inicio->subDay()->setTime(19, 40),
                'updated_at' => $inicio->setTime(8, 15),
            ]);

            foreach ($productos as $pid) {
                $this->e->lote->poner('promotion_products', ['promotion_id' => $id, 'products_id' => $pid]);
            }

            if ($enviada && $tipo !== Promotion::SIN_DESCUENTO) {
                $vigentes[] = [
                    'id' => $id, 'tipo' => $tipo, 'pct' => $pct, 'lleva' => $lleva, 'paga' => $paga,
                    'productos' => $productos, 'desde' => $inicio, 'hasta' => $fin, 'max' => $tipo === Promotion::NXM ? 40 : null,
                ];
                $this->e->usosDePromocion[$id] = 0;
            }
        }

        return $vigentes;
    }

    /** @return list<int> */
    private function buscar(array $surtido, array $patrones): array
    {
        $ids = [];

        foreach ($surtido as $p) {
            foreach ($patrones as $patron) {
                if (mb_stripos($p['nombre'], $patron) !== false) {
                    $ids[] = $p['id'];
                    break;
                }
            }
        }

        return array_values(array_unique(array_slice($ids, 0, 8)));
    }

    // Los cupos de fiado: a los afiliados que más compran, una semana después de empezar a vender.
    private function fiados(array $n): array
    {
        $porPeso = $n['clientela'];
        usort($porPeso, fn ($a, $b) => $b['peso'] <=> $a['peso']);
        [$min, $max] = self::CUPO[$n['oficio']];
        $creditos = [];

        foreach (array_slice($porPeso, 0, $n['diarios'] > 0 ? self::CON_CUPO[$n['oficio']] : 0) as $c) {
            $comprador = $this->e->compradores[$c['i']];
            $desde = CarbonImmutable::parse(max($comprador['alta'], $n['primera']))->addDays(7)->setTime(17, 30);
            $desde = $desde->min($this->e->ahora->subDay());
            $id = $this->e->lote->id('store_credits', 'id');
            $cupo = round(mt_rand($min, $max) / 10000) * 10000;

            $this->e->lote->poner('store_credits', [
                'id' => $id, 'busines_id' => $n['id'], 'user_id' => $comprador['user_id'], 'credit_limit' => $cupo,
                'created_by' => $n['dueno_user'], 'created_at' => $desde, 'updated_at' => $desde,
            ]);

            $creditos[$c['i']] = ['id' => $id, 'cupo' => (float) $cupo, 'usado' => 0.0, 'desde' => $desde];
        }

        return $creditos;
    }
}
