<?php

declare(strict_types=1);

namespace Database\Seeders\DemostracionQa;

use App\Models\Conjunto\ComplexStaff;
use Carbon\CarbonImmutable;

// El conjunto de demostración: su ficha, su personal, los turnos y un mes del libro de la portería.
final class Conjunto
{
    private const DIAS = 30;

    private const HORAS = [
        0 => 4, 1 => 2, 5 => 3, 6 => 12, 7 => 25, 8 => 30, 9 => 30, 10 => 35, 11 => 45, 12 => 80, 13 => 80,
        14 => 50, 15 => 40, 16 => 40, 17 => 50, 18 => 80, 19 => 95, 20 => 80, 21 => 50, 22 => 20, 23 => 8,
    ];

    // Todas las columnas de una entrada, en un orden: así van en lotes grandes.
    private const VACIA = [
        'complex_id' => null, 'kind' => 'domiciliario', 'domiciliary_id' => null, 'method' => 'manual',
        'visitor_name' => null, 'visitor_document' => null, 'visitor_phone' => null, 'visitor_company' => null,
        'vehicle_plate' => null, 'tower' => null, 'apartment' => null, 'authorized_by' => null, 'notes' => null,
        'exited_at' => null, 'exit_registered_by' => null, 'orders_count' => 0, 'orders' => null,
        'registered_by' => null, 'created_at' => null, 'updated_at' => null,
    ];

    public function __construct(private Escenario $e) {}

    public function edificio(): void
    {
        $c = Guion::CONJUNTO;
        $id = $this->e->lote->id('residential_complexes', 'complex_id');
        $alta = $this->e->haceDias($c['alta'], 10);

        $this->e->lote->poner('residential_complexes', [
            'complex_id' => $id, 'name' => $c['nombre'], 'address' => $c['direccion'], 'phone' => $c['telefono'],
            'email' => 'administracion.torres.campestre@'.Escenario::DOMINIO, 'admin_name' => Guion::ADMINISTRADORA[0],
            'nit' => $c['nit'], 'require_authorization' => 1, 'latitude' => $c['lat'], 'longitude' => $c['lng'],
            'gate_notes' => 'Domicilios hasta la portería después de las 10 p. m. Visitas: anunciar por citófono.',
            'municipality_id' => $this->e->municipios[$c['municipio']], 'state' => 1, 'people_count' => 520, 'towers_count' => $c['torres'],
            'apartments_per_tower' => $c['pisos'] * $c['por_piso'], 'horas_alerta_adentro' => 4,
            'created_at' => $alta, 'updated_at' => $alta,
        ]);

        $this->e->conjunto += ['id' => $id, 'alta' => $alta];
    }

    public function porteria(): void
    {
        $this->personal();
        $this->turnos();
        $this->entradas();
    }

    private function personal(): void
    {
        $alta = $this->e->conjunto['alta'];

        $this->e->lote->poner('complex_staff', $this->ficha($this->e->conjunto['dueno'], ComplexStaff::DUENO, null, $alta));

        foreach ($this->e->conjunto['celadores'] as $userId) {
            $this->e->lote->poner('complex_staff', $this->ficha($userId, ComplexStaff::CELADOR, $this->e->conjunto['dueno'], $alta->addDay()));
        }
    }

    /** @return array<string, mixed> */
    private function ficha(int $userId, string $rol, ?int $creadoPor, CarbonImmutable $cuando): array
    {
        return [
            'user_id' => $userId, 'complex_id' => $this->e->conjunto['id'], 'role' => $rol, 'state' => 1,
            'created_by' => $creadoPor, 'created_at' => $cuando, 'updated_at' => $cuando,
        ];
    }

    // Un turno de 6 a 14 y otro de 14 a 6 por día; el que corre ahora queda abierto.
    private function turnos(): void
    {
        for ($dia = $this->e->hoy->subDays(self::DIAS + 1); $dia->lte($this->e->hoy); $dia = $dia->addDay()) {
            foreach ([[6, 8], [14, 16]] as [$hora, $largo]) {
                $inicio = $dia->setTime($hora, 0);
                $llego = $inicio->subMinutes(mt_rand(0, 12));
                $salio = $inicio->addHours($largo)->addMinutes(mt_rand(0, 15));

                if ($llego->gt($this->e->ahora)) {
                    continue;
                }

                $abierto = $salio->gt($this->e->ahora);
                $this->e->lote->poner('complex_shifts', [
                    'complex_id' => $this->e->conjunto['id'], 'user_id' => $this->celadorDelTurno($inicio),
                    'started_at' => $llego, 'ended_at' => $abierto ? null : $salio, 'created_at' => $llego,
                    'updated_at' => $abierto ? $llego : $salio,
                ]);
            }
        }
    }

    // Ramiro de mañana y Yesenia de tarde; Hernando releva los domingos y un día de cada cuatro.
    private function celadorDelTurno(CarbonImmutable $inicio): int
    {
        $celadores = $this->e->conjunto['celadores'];
        $turno = $inicio->hour === 6 ? 0 : 1;
        $relevo = ($inicio->isSunday() || $inicio->dayOfYear % 4 === 0) && $turno === $inicio->dayOfYear % 2;

        return $relevo ? $celadores[2] : $celadores[$turno];
    }

    // Quién estaba en la puerta a esa hora: el turno de la tarde sigue hasta las 6 del otro día.
    public function deTurno(CarbonImmutable $cuando): int
    {
        $inicio = match (true) {
            $cuando->hour >= 6 && $cuando->hour < 14 => $cuando->setTime(6, 0),
            $cuando->hour >= 14 => $cuando->setTime(14, 0),
            default => $cuando->subDay()->setTime(14, 0),
        };

        return $this->celadorDelTurno($inicio);
    }

    private function entradas(): void
    {
        foreach ($this->e->alConjunto as $p) {
            $this->domiciliario($p);
        }

        for ($n = self::DIAS - 1; $n >= 0; $n--) {
            $dia = $this->e->hoy->subDays($n);
            $cuantas = $dia->isWeekend() ? mt_rand(10, 16) : mt_rand(6, 12);

            for ($k = 0; $k < $cuantas; $k++) {
                $cuando = $dia->setTime((int) Azar::elegir(self::HORAS), mt_rand(0, 59), mt_rand(0, 59));

                if ($cuando->lt($this->e->ahora->subMinutes(3))) {
                    $this->visita((string) Azar::elegir(['visitante' => 50, 'servicio' => 18, 'domicilio_externo' => 32]), $cuando, false);
                }
            }
        }

        // Siguen adentro hoy: una visita, un domicilio de otra app y un técnico pasado de las 4 h.
        $minutos = max(5, (int) $this->e->hoy->diffInMinutes($this->e->ahora, true));
        foreach ([['visitante', 50], ['domicilio_externo', 6], ['servicio', 300]] as [$clase, $hace]) {
            $this->visita($clase, $this->e->ahora->subMinutes(min($hace, $minutos - 2)), true);
        }
    }

    /** @param array<string, mixed> $p */
    private function domiciliario(array $p): void
    {
        $entra = $p['adentro'] ? $p['entrega']->subMinutes(2) : $p['entrega']->subMinutes(mt_rand(2, 4));
        $sale = $p['entrega']->addMinutes(mt_rand(2, 6))->addSeconds(mt_rand(0, 59));
        $anotada = ! $p['adentro'] && $sale->lte($this->e->ahora);

        $this->e->lote->poner('complex_entries', array_merge(self::VACIA, [
            'complex_id' => $this->e->conjunto['id'], 'domiciliary_id' => $p['domi'],
            'method' => Azar::si(72) ? 'codigo' : 'cedula', 'orders_count' => 1,
            'orders' => json_encode([$p['pedido']], JSON_UNESCAPED_UNICODE), 'registered_by' => $this->deTurno($entra),
            'exited_at' => $anotada ? $sale : null, 'exit_registered_by' => $anotada ? $this->deTurno($sale) : null,
            'created_at' => $entra, 'updated_at' => $anotada ? $sale : $entra,
        ]));
    }

    private function visita(string $clase, CarbonImmutable $cuando, bool $adentro): void
    {
        [$min, $max] = match ($clase) {
            'domicilio_externo' => [3, 10],
            'servicio' => [40, 180],
            default => [25, 240],
        };
        $sale = $cuando->addMinutes(mt_rand($min, $max));
        $olvido = ! $cuando->isSameDay($this->e->ahora) && mt_rand(1, 50) === 1;
        $anotada = ! $adentro && ! $olvido && $sale->lte($this->e->ahora);

        $this->e->lote->poner('complex_entries', array_merge(self::VACIA, $this->quien($clase), [
            'complex_id' => $this->e->conjunto['id'], 'kind' => $clase, 'registered_by' => $this->deTurno($cuando),
            'exited_at' => $anotada ? $sale : null, 'exit_registered_by' => $anotada ? $this->deTurno($sale) : null,
            'created_at' => $cuando, 'updated_at' => $anotada ? $sale : $cuando,
        ]));
    }

    // Lo que anota el celador de quien no es usuario de la plataforma.
    private function quien(string $clase): array
    {
        $residentes = array_values(array_filter($this->e->compradores, fn ($c) => $c['residente']));
        $c = Guion::CONJUNTO;

        if (Azar::si(70)) {
            $r = Azar::uno($residentes);
            [$torre, $apto, $autoriza] = [$r['torre'], $r['apto'], $r['nombre']];
        } else {
            $torre = (string) mt_rand(1, $c['torres']);
            $apto = mt_rand(1, $c['pisos']).'0'.mt_rand(1, $c['por_piso']);
            $autoriza = "Residente del {$torre}-{$apto} (citófono)";
        }

        $datos = [
            'visitor_name' => Azar::uno(Guion::NOMBRES).' '.Azar::uno(Guion::APELLIDOS).' '.Azar::uno(Guion::APELLIDOS),
            'visitor_document' => (string) mt_rand(10000000, 1199999999),
            'visitor_phone' => Azar::si(35) ? null : '3'.mt_rand(0, 2).mt_rand(0, 9).' '.mt_rand(100, 999).' '.mt_rand(1000, 9999),
            'tower' => $torre, 'apartment' => $apto, 'authorized_by' => $autoriza,
        ];

        if ($clase === 'servicio') {
            [$empresa, $nota] = Azar::uno(Guion::SERVICIOS);

            return $datos + ['visitor_company' => $empresa, 'notes' => $nota, 'vehicle_plate' => Azar::si(50) ? $this->placa(false) : null];
        }

        if ($clase === 'domicilio_externo') {
            return array_merge($datos, [
                'visitor_company' => Azar::uno(Guion::OTRAS_APPS), 'vehicle_plate' => Azar::si(65) ? $this->placa(true) : null,
                'visitor_document' => Azar::si(50) ? $datos['visitor_document'] : null,
            ]);
        }

        return $datos + ['vehicle_plate' => Azar::si(25) ? $this->placa(false) : null];
    }

    private function placa(bool $moto): string
    {
        $letras = fn (int $n) => implode('', array_map(fn () => chr(mt_rand(65, 90)), range(1, $n)));

        return $moto
            ? $letras(3).str_pad((string) mt_rand(0, 99), 2, '0', STR_PAD_LEFT).$letras(1)
            : $letras(3).str_pad((string) mt_rand(0, 999), 3, '0', STR_PAD_LEFT);
    }
}
