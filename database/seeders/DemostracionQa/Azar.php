<?php

declare(strict_types=1);

namespace Database\Seeders\DemostracionQa;

// Azar con semilla fija (mt_rand): dos corridas el mismo día dan los mismos datos.
final class Azar
{
    public static function entre(int $desde, int $hasta): int
    {
        return mt_rand($desde, $hasta);
    }

    public static function si(int $porCiento): bool
    {
        return mt_rand(1, 100) <= $porCiento;
    }

    /** @param array<int|string, int|float> $pesos */
    public static function elegir(array $pesos): int|string
    {
        $total = (int) round(array_sum($pesos) * 100);
        $tiro = mt_rand(1, max(1, $total));

        foreach ($pesos as $llave => $peso) {
            $tiro -= (int) round($peso * 100);
            if ($tiro <= 0) {
                return $llave;
            }
        }

        return array_key_last($pesos);
    }

    /** @param list<mixed> $lista */
    public static function uno(array $lista): mixed
    {
        return $lista[mt_rand(0, count($lista) - 1)];
    }

    /** @param array<array-key, mixed> $lista */
    public static function barajar(array $lista): array
    {
        $lista = array_values($lista);

        for ($i = count($lista) - 1; $i > 0; $i--) {
            $j = mt_rand(0, $i);
            [$lista[$i], $lista[$j]] = [$lista[$j], $lista[$i]];
        }

        return $lista;
    }

    // Distancia en kilómetros entre dos puntos (haversine).
    public static function km(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;

        return 2 * 6371.0 * asin(min(1.0, sqrt($a)));
    }
}
