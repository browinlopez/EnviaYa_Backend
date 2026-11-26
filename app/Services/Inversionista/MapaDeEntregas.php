<?php

declare(strict_types=1);

namespace App\Services\Inversionista;

/**
 * EL MAPA DE EXPANSIÓN: HASTA DÓNDE SE ENTREGA Y DÓNDE SE COMPRA MÁS.
 *
 * Dos capas para el mapa del módulo Expansión, las dos con los filtros de la
 * barra (periodo, ciudad y tipo de negocio) y sobre pedidos entregados y
 * pagados que fueron domicilio (no lo recogido en el local):
 *
 *  · RADIO DE ENTREGAS por ciudad: el percentil 90 de la distancia en línea
 *    recta entre el negocio y la dirección de entrega. «Nueve de cada diez
 *    entregas llegan a menos de esto». Solo con 5 entregas medidas o más: con
 *    menos, el radio sería prácticamente la distancia a una casa.
 *
 *  · DÓNDE SE COMPRA MÁS: los pedidos contados por CELDA de una cuadrícula de
 *    0,004° (unos 440 m de lado), y solo las celdas con 3 pedidos o más
 *    (k-anonimato). Cada celda viaja como su CENTRO, no como el punto de
 *    nadie: una celda con un solo comprador no sale nunca, y dentro de una
 *    que sale no hay forma de saber en qué casa se entregó.
 *
 * Las coordenadas de negocios y direcciones solo se leen acá, en filas de
 * trabajo que no salen del servicio. Hay una prueba que lo vigila.
 */
class MapaDeEntregas extends BaseDelInversionista
{
    /** Lado de la celda, en grados (~440 m en latitud). */
    public const CELDA = 0.004;

    /** Pedidos mínimos para que una celda se publique. */
    public const MINIMO_POR_CELDA = 3;

    /** Entregas mínimas para publicar el radio de una ciudad. */
    public const MINIMO_PARA_RADIO = 5;

    /** Más lejos que esto no es un domicilio: es una coordenada mal puesta. */
    private const TOPE_KM = 50;

    /** Celdas como mucho: el mapa no necesita más para verse. */
    private const MAXIMO_DE_CELDAS = 400;

    /**
     * @return array{radios: array<int, array{radio_km: ?float, entregas_medidas: int}>, calor: array}
     */
    public function datos(array $v, array $f): array
    {
        $filas = $this->entregados($f, $v['desde'], $v['hasta'], true)
            ->join('user_address as ua', 'ua.address_id', '=', 'o.address_id')
            ->where(fn ($q) => $q->whereNull('o.pickup')->orWhere('o.pickup', 0))
            ->whereNotNull('ua.latitude')
            ->whereNotNull('ua.longitude')
            ->get([
                'b.municipality_id as m',
                'b.latitude as blat',
                'b.longitude as blng',
                'ua.latitude as alat',
                'ua.longitude as alng',
            ]);

        $distancias = [];
        $celdas = [];

        foreach ($filas as $r) {
            $alat = (float) $r->alat;
            $alng = (float) $r->alng;
            if ($alat === 0.0 && $alng === 0.0) {
                continue;
            }

            $clave = (int) floor($alat / self::CELDA).':'.(int) floor($alng / self::CELDA);
            $celdas[$clave] = ($celdas[$clave] ?? 0) + 1;

            if ($r->m !== null && $r->blat !== null && $r->blng !== null && ((float) $r->blat !== 0.0 || (float) $r->blng !== 0.0)) {
                $km = $this->kilometros((float) $r->blat, (float) $r->blng, $alat, $alng);
                if ($km <= self::TOPE_KM) {
                    $distancias[(int) $r->m][] = $km;
                }
            }
        }

        $radios = [];
        foreach ($distancias as $m => $lista) {
            sort($lista);
            $n = count($lista);
            $radios[$m] = [
                'radio_km' => $n >= self::MINIMO_PARA_RADIO
                    ? round($lista[(int) ceil(0.9 * $n) - 1], 1)
                    : null,
                'entregas_medidas' => $n,
            ];
        }

        $publicables = array_filter($celdas, fn ($n) => $n >= self::MINIMO_POR_CELDA);
        arsort($publicables);

        $lista = [];
        foreach (array_slice($publicables, 0, self::MAXIMO_DE_CELDAS, true) as $clave => $n) {
            [$i, $j] = array_map('intval', explode(':', (string) $clave));
            $lista[] = [
                // El centro de la celda, nunca un punto de entrega.
                'lat' => round(($i + 0.5) * self::CELDA, 4),
                'lng' => round(($j + 0.5) * self::CELDA, 4),
                'pedidos' => $n,
            ];
        }

        return [
            'radios' => $radios,
            'calor' => [
                'celda_grados' => self::CELDA,
                'minimo_por_celda' => self::MINIMO_POR_CELDA,
                'celdas' => $lista,
                // Cuántos pedidos quedaron fuera por estar en celdas chicas:
                // se dice, para que nadie lea el mapa como el total.
                'pedidos_ocultos' => array_sum($celdas) - array_sum($publicables),
            ],
        ];
    }

    /** Distancia en línea recta (haversine), en kilómetros. */
    private function kilometros(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $r = 6371.0;
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;

        return 2 * $r * asin(min(1.0, sqrt($a)));
    }
}
