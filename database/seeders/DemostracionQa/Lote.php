<?php

declare(strict_types=1);

namespace Database\Seeders\DemostracionQa;

use Illuminate\Support\Facades\DB;

// Junta las filas y las inserta de a cientos: contra una base remota, fila por fila tarda horas.
final class Lote
{
    // Orden de inserción: cada tabla después de aquellas a las que apunta con una foránea.
    private const ORDEN = [
        'user', 'residential_complexes', 'buyer', 'owner', 'domiciliary', 'user_address',
        'buyer_complex', 'complex_staff', 'business', 'owner_busines', 'business_domiciliary',
        'products', 'products_business', 'gastos_del_negocio', 'gastos_del_mes', 'promotions',
        'promotion_products', 'business_user_affiliations', 'store_credits', 'orderssales',
        'orderssales_detail', 'orders_promotions', 'payments', 'store_credit_movements',
        'cash_deposits', 'cash_movements', 'order_geolocation', 'business_reviews',
        'domiciliary_reviews', 'ventas_mostrador', 'ventas_mostrador_lineas', 'complex_shifts',
        'complex_entries',
    ];

    // Tope de valores por sentencia: MySQL admite 65535 y SQLite 32766.
    private const VALORES = 20000;

    /** @var array<string, int> */
    private array $siguiente = [];

    /** @var array<string, list<array<string, mixed>>> */
    private array $filas = [];

    /** @var array<string, int> */
    private array $creadas = [];

    // Id explícito, después del mayor que ya existe: así los hijos se arman sin ida y vuelta.
    public function id(string $tabla, string $llave): int
    {
        $this->siguiente[$tabla] ??= (int) DB::table($tabla)->max($llave) + 1;

        return $this->siguiente[$tabla]++;
    }

    /** @param array<string, mixed> $fila */
    public function poner(string $tabla, array $fila): void
    {
        $this->filas[$tabla][] = $fila;
    }

    public function guardar(): void
    {
        $tablas = array_unique([...array_intersect(self::ORDEN, array_keys($this->filas)), ...array_keys($this->filas)]);

        foreach ($tablas as $tabla) {
            $this->insertar($tabla, $this->filas[$tabla]);
            $this->creadas[$tabla] = ($this->creadas[$tabla] ?? 0) + count($this->filas[$tabla]);
        }

        $this->filas = [];
    }

    /** @return array<string, int> */
    public function creadas(): array
    {
        return $this->creadas;
    }

    /** @param list<array<string, mixed>> $filas */
    private function insertar(string $tabla, array $filas): void
    {
        $grupos = [];

        foreach ($filas as $fila) {
            $grupos[implode(',', array_keys($fila))][] = $fila;
        }

        foreach ($grupos as $grupo) {
            $tamano = max(1, intdiv(self::VALORES, max(1, count($grupo[0]))));

            foreach (array_chunk($grupo, $tamano) as $parte) {
                DB::table($tabla)->insert($parte);
            }
        }
    }
}
