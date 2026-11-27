<?php

declare(strict_types=1);

namespace Database\Seeders\DemostracionQa;

use App\Models\Conjunto\ComplexStaff;
use Carbon\CarbonImmutable;

// Dueños, domiciliarios, personal del conjunto y compradores con su dirección.
final class Personas
{
    private const COMPRADOR = 1;

    private const TENDERO = 2;

    private const DOMICILIARIO = 3;

    public function __construct(private Escenario $e) {}

    public function sembrar(): void
    {
        $this->duenos();
        $this->domiciliarios();
        $this->personalDelConjunto();
        $this->compradores();
    }

    private function duenos(): void
    {
        foreach (Guion::DUENOS as $clave => [$nombre, $telefono, $cedula]) {
            $dias = max(array_map(fn ($n) => $n['alta'], array_filter(Guion::NEGOCIOS, fn ($n) => $n['dueno'] === $clave)));
            $alta = $this->e->haceDias($dias + 4, 10, 15);
            $correo = $this->e->correo($nombre);
            $userId = $this->usuario($nombre, $correo, self::TENDERO, $alta, $telefono);
            $ownerId = $this->e->lote->id('owner', 'owner_id');

            $this->e->lote->poner('owner', [
                'owner_id' => $ownerId, 'user_id' => $userId, 'document_type_id' => 1,
                'document_number' => $cedula, 'state' => 1,
            ]);

            $this->e->duenos[$clave] = ['user_id' => $userId, 'owner_id' => $ownerId, 'correo' => $correo];
        }
    }

    private function domiciliarios(): void
    {
        foreach (Guion::DOMICILIARIOS as $i => [$nombre, $cedula, $negocios, $disponible, $ciudad]) {
            $dias = max(array_map(fn ($k) => Guion::NEGOCIOS[$k]['alta'], $negocios));
            $alta = $this->e->haceDias(max(20, $dias - $i * 9), 8, 30);
            $userId = $this->usuario($nombre, $this->e->correo($nombre, 'domi.'), self::DOMICILIARIO, $alta, $this->telefono());
            $id = $this->e->lote->id('domiciliary', 'domiciliary_id');

            $this->e->lote->poner('domiciliary', [
                'domiciliary_id' => $id, 'user_id' => $userId, 'available' => $disponible, 'document' => $cedula,
                'contract_signed_at' => $alta->addHour(), 'contract_city' => $ciudad, 'qualification' => 0,
                'state' => 1,
            ]);

            $this->e->domiciliarios[] = [
                'id' => $id, 'user_id' => $userId, 'nombre' => $nombre, 'documento' => $cedula,
                'negocios' => $negocios, 'alta' => $alta,
            ];
        }
    }

    private function personalDelConjunto(): void
    {
        $alta = $this->e->haceDias(Guion::CONJUNTO['alta'] + 1, 11);
        [$nombre, $telefono] = Guion::ADMINISTRADORA;
        $this->e->conjunto['dueno'] = $this->usuario($nombre, $this->e->correo($nombre, 'admin.'), ComplexStaff::ROL_DUENO, $alta, $telefono);
        $this->e->conjunto['celadores'] = [];

        foreach (Guion::CELADORES as [$nombre, $telefono]) {
            $correo = $this->e->correo($nombre, 'celador.');
            $this->e->conjunto['celadores'][] = $this->usuario($nombre, $correo, ComplexStaff::ROL_CELADOR, $alta, $telefono);
        }
    }

    private function compradores(): void
    {
        $nombres = $this->nombres();

        foreach (Guion::ZONAS as $zona => [$municipio, $lat, $lng, $cuantos, $barrio, $calles, $carreras]) {
            $altas = $this->altas($this->inicioDe($zona), $cuantos);

            foreach ($altas as $k => $alta) {
                $direccion = $this->direccion($barrio, $calles, $carreras);
                $punto = [$lat + mt_rand(-22, 22) / 10000, $lng + mt_rand(-22, 22) / 10000];
                $this->comprador(array_shift($nombres), $zona, $municipio, $alta, $k, $direccion, $punto);
            }
        }

        $c = Guion::CONJUNTO;
        $ocupados = [];

        foreach ($this->altas($this->e->haceDias($c['alta'] - 2), Guion::RESIDENTES) as $k => $alta) {
            do {
                $torre = (string) mt_rand(1, $c['torres']);
                $apto = mt_rand(1, $c['pisos']).'0'.mt_rand(1, $c['por_piso']);
            } while (isset($ocupados["{$torre}-{$apto}"]));

            $ocupados["{$torre}-{$apto}"] = true;
            $punto = [$c['lat'], $c['lng']];
            $this->comprador(array_shift($nombres), 'conjunto', $c['municipio'], $alta, $k, $c['direccion'], $punto, [$torre, $apto]);
        }
    }

    /** @param array{0: float, 1: float} $punto */
    private function comprador(
        string $nombre,
        string $zona,
        string $municipio,
        CarbonImmutable $alta,
        int $orden,
        string $direccion,
        array $punto,
        ?array $puerta = null,
    ): void {
        $userId = $this->usuario($nombre, $this->e->correo($nombre), self::COMPRADOR, $alta, $this->telefono(), $direccion);
        $buyerId = $this->e->lote->id('buyer', 'buyer_id');
        $addressId = $this->e->lote->id('user_address', 'address_id');
        $complexId = $puerta ? $this->e->conjunto['id'] : null;

        $this->e->lote->poner('buyer', [
            'buyer_id' => $buyerId, 'user_id' => $userId, 'qualification' => 0,
            'belongs_to_complex' => $puerta ? 1 : 0, 'state' => 1,
        ]);
        $this->e->lote->poner('user_address', [
            'address_id' => $addressId, 'user_id' => $userId, 'address' => $direccion, 'complex_id' => $complexId,
            'tower' => $puerta[0] ?? null, 'apartment' => $puerta[1] ?? null, 'latitude' => round($punto[0], 7),
            'longitude' => round($punto[1], 7), 'state' => 1, 'municipality_id' => $this->e->municipios[$municipio], 'nombre' => 'Casa',
        ]);

        if ($puerta) {
            $this->e->lote->poner('buyer_complex', [
                'buyer_id' => $buyerId, 'complex_id' => $complexId, 'created_at' => $alta, 'updated_at' => $alta,
            ]);
        }

        // Unos piden mucho y otros casi nada; uno de cada doce se registró y nunca pidió.
        $sinOferta = in_array($zona, ['campestre', 'galapa'], true);
        $peso = ($sinOferta || ($orden > 2 && Azar::si(8))) ? 0.0 : 10 / ((mt_rand(1, 12)) ** 0.8);
        $vida = (int) $alta->diffInDays($this->e->ahora, true);
        $baja = ($orden > 2 && $vida > 60 && Azar::si(15)) ? $alta->addDays(mt_rand(30, $vida - 20)) : null;

        $this->e->compradores[] = [
            'user_id' => $userId, 'buyer_id' => $buyerId, 'address_id' => $addressId, 'nombre' => $nombre,
            'zona' => $zona, 'lat' => $punto[0], 'lng' => $punto[1], 'alta' => $alta, 'baja' => $baja,
            'peso' => $peso, 'residente' => $puerta !== null, 'torre' => $puerta[0] ?? null,
            'apto' => $puerta[1] ?? null,
        ];
    }

    // Desde cuándo hay a quién comprarle en la zona: veinte días antes del primer negocio.
    private function inicioDe(string $zona): CarbonImmutable
    {
        $dias = array_map(fn ($n) => $n['alta'], array_filter(Guion::NEGOCIOS, fn ($n) => isset($n['zonas'][$zona])));

        return $this->e->haceDias($dias === [] ? 200 : max($dias) + 20);
    }

    // Las altas crecen con el tiempo (densidad lineal) y los tres primeros llegan al arrancar.
    private function altas(CarbonImmutable $inicio, int $cuantos): array
    {
        $fin = $this->e->ahora->subDay();
        $minutos = max(60, (int) $inicio->diffInMinutes($fin, true));
        $altas = [];

        for ($k = 0; $k < $cuantos; $k++) {
            $fraccion = $k < 3 ? $k * 0.02 + mt_rand(0, 100) / 10000 : sqrt(mt_rand(0, 10000) / 10000);
            $altas[] = $inicio->addMinutes((int) ($minutos * $fraccion))->setTime(mt_rand(7, 21), mt_rand(0, 59));
        }

        return $altas;
    }

    /** @return list<string> */
    private function nombres(): array
    {
        $usados = [];

        foreach ([...array_column(Guion::DUENOS, 0), ...array_column(Guion::DOMICILIARIOS, 0), ...array_column(Guion::CELADORES, 0)] as $n) {
            $usados[$n] = true;
        }

        $nombres = Azar::barajar(Guion::NOMBRES);
        $apellidos = Azar::barajar(Guion::APELLIDOS);
        $lista = [];

        for ($k = 0; count($lista) < 200; $k++) {
            $completo = $nombres[$k % count($nombres)].' '.$apellidos[($k * 7 + intdiv($k, count($nombres))) % count($apellidos)];

            if (! isset($usados[$completo])) {
                $usados[$completo] = true;
                $lista[] = $completo;
            }
        }

        return $lista;
    }

    /** @param array{0:int, 1:int} $calles @param array{0:int, 1:int} $carreras */
    private function direccion(string $barrio, array $calles, array $carreras): string
    {
        $calle = mt_rand($calles[0], $calles[1]);
        $carrera = mt_rand($carreras[0], $carreras[1]);
        $letra = Azar::uno(['', '', '', 'A', 'B', 'C']);

        return Azar::si(50)
            ? "Calle {$calle}{$letra} #{$carrera}-".mt_rand(5, 95).", {$barrio}"
            : "Carrera {$carrera}{$letra} #{$calle}-".mt_rand(5, 95).", {$barrio}";
    }

    private function usuario(string $nombre, string $correo, int $rol, CarbonImmutable $alta, ?string $telefono, ?string $direccion = null): int
    {
        $id = $this->e->lote->id('user', 'user_id');

        $this->e->lote->poner('user', [
            'user_id' => $id, 'name' => $nombre, 'email' => $correo, 'email_verified_at' => $alta,
            'password' => $this->e->clave, 'phone' => $telefono, 'address' => $direccion, 'rol' => $rol,
            'qualification' => 0, 'state' => 1, 'must_change_password' => 0, 'policy_accepted_at' => $alta,
            'created_at' => $alta, 'updated_at' => $alta,
        ]);

        $this->e->cuentas[$rol][] = $correo;

        return $id;
    }

    private function telefono(): string
    {
        return '3'.Azar::uno(['00', '01', '04', '05', '10', '13', '15', '18', '20', '23']).' '.mt_rand(200, 899).' '.mt_rand(1000, 9999);
    }
}
