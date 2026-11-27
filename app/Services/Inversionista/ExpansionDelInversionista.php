<?php

declare(strict_types=1);

namespace App\Services\Inversionista;

use App\Services\GeneradorDeReportes;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * EXPANSIÓN: DÓNDE ESTÁ VECIPA'YA Y DÓNDE LA ESTÁN ESPERANDO.
 *
 * Por cada ciudad: los negocios activos (con su reparto por tipo), los
 * conjuntos activos y los vecinos registrados en ellos, los pedidos del
 * periodo, los compradores con dirección ahí, cuántos compradores
 * hay por negocio (la cobertura) y desde cuándo hay presencia.
 *
 * Entra toda ciudad donde haya algo: un negocio, un conjunto, un pedido o un
 * comprador con dirección ahí. Una ciudad ESPERANDO es la que tiene demanda
 * (compradores con dirección ahí o conjuntos activos) y ningún negocio
 * activo: no es ruido, es demanda esperando oferta, que es justo lo que un
 * inversionista quiere ver. La fila «Sin ciudad» (negocios y compradores sin
 * municipio) no es una ciudad: es un hueco en los datos y no se cuenta como
 * ciudad en ninguna cifra.
 *
 * EL PUNTO DEL MAPA es el promedio de las coordenadas de los negocios activos
 * de la ciudad (o, si no tiene, de sus conjuntos), REDONDEADO A DOS DECIMALES
 * (unos 1,1 km). No es la dirección de nadie: es dónde cae la ciudad en el
 * mapa, con la precisión justa para que tres municipios vecinos no se monten.
 *
 * EL RADIO DE ENTREGAS de cada ciudad y el MAPA DE CALOR de dónde se compra
 * los arma `MapaDeEntregas`, con sus propios umbrales de privacidad.
 */
class ExpansionDelInversionista extends BaseDelInversionista
{
    public function __construct(GeneradorDeReportes $reportes, private MapaDeEntregas $mapa)
    {
        parent::__construct($reportes);
    }

    public function datos(array $v, array $f): array
    {
        $mapa = $this->mapa->datos($v, $f);

        $ciudades = array_map(fn (array $c) => $c + [
            'radio_km' => $c['municipality_id'] === null ? null : ($mapa['radios'][$c['municipality_id']]['radio_km'] ?? null),
            'entregas_medidas' => $c['municipality_id'] === null ? 0 : ($mapa['radios'][$c['municipality_id']]['entregas_medidas'] ?? 0),
        ], $this->porCiudad($v, $f));

        $conCiudad = array_filter($ciudades, fn ($c) => $c['municipality_id'] !== null);
        $sinCiudad = array_values(array_filter($ciudades, fn ($c) => $c['municipality_id'] === null));

        return [
            'resumen' => [
                'ciudades_con_negocios' => count(array_filter($conCiudad, fn ($c) => $c['negocios'] > 0)),
                // Demanda (compradores o conjuntos activos) y ni un negocio: la próxima ciudad.
                'ciudades_sin_oferta' => count(array_filter($conCiudad, fn ($c) => $c['esperando'])),
                // «Sin ciudad» no es una ciudad: es un hueco en los datos.
                'negocios_sin_ciudad' => $sinCiudad[0]['negocios'] ?? 0,
                'departamentos' => count(array_unique(array_filter(array_map(
                    fn ($c) => $c['negocios'] > 0 ? $c['departamento'] : null,
                    $conCiudad,
                )))),
                'conjuntos_activos' => array_sum(array_column($ciudades, 'conjuntos')),
                'residentes_en_conjuntos' => array_sum(array_column($ciudades, 'residentes')),
            ],
            'por_ciudad' => $ciudades,
            'calor' => $mapa['calor'],
        ];
    }

    public function porCiudad(array $v, array $f): array
    {
        $filas = [];
        $fila = function (?int $id) use (&$filas): string {
            $k = $id === null ? 'sin' : (string) $id;
            $filas[$k] ??= [
                'municipality_id' => $id,
                'negocios' => 0,
                'por_tipo' => [],
                'conjuntos' => 0,
                'residentes' => 0,
                'pedidos' => 0,
                'compradores' => 0,
                'desde' => null,
                'punto' => null,
                'punto_conjuntos' => null,
            ];

            return $k;
        };
        $id = fn ($m) => $m === null ? null : (int) $m;

        $tipos = collect($this->tipos())->keyBy('id');

        $negocios = $this->negociosAl($f, $v['hasta'])
            ->selectRaw('b.municipality_id as m, b.type as tipo, COUNT(*) as n, MIN(b.created_at) as desde')
            ->groupBy('b.municipality_id', 'b.type')
            ->get();

        foreach ($negocios as $n) {
            $k = $fila($id($n->m));
            $filas[$k]['negocios'] += (int) $n->n;

            $oficio = $tipos[$n->tipo] ?? ['clave' => 'otro', 'nombre' => 'Otro'];
            $clave = $oficio['clave'];
            $filas[$k]['por_tipo'][$clave] ??= ['clave' => $clave, 'nombre' => $oficio['nombre'], 'negocios' => 0];
            $filas[$k]['por_tipo'][$clave]['negocios'] += (int) $n->n;

            if ($n->desde && ($filas[$k]['desde'] === null || $n->desde < $filas[$k]['desde'])) {
                $filas[$k]['desde'] = $n->desde;
            }
        }

        // El centro de cada ciudad para el mapa, con los mismos negocios.
        $centros = $this->negociosAl($f, $v['hasta'])
            ->whereNotNull('b.municipality_id')
            ->whereNotNull('b.latitude')
            ->whereNotNull('b.longitude')
            ->selectRaw('b.municipality_id as m, AVG(b.latitude) as lat, AVG(b.longitude) as lng')
            ->groupBy('b.municipality_id')
            ->get();

        foreach ($centros as $c) {
            $filas[$fila((int) $c->m)]['punto'] = $this->punto($c->lat, $c->lng);
        }

        $conjuntos = DB::table('residential_complexes as c')
            ->where('c.state', 1)
            ->when($f['municipality_id'], fn ($q, $m) => $q->where('c.municipality_id', $m))
            ->selectRaw('c.municipality_id as m, COUNT(*) as n, AVG(c.latitude) as lat, AVG(c.longitude) as lng')
            ->groupBy('c.municipality_id')
            ->get();

        foreach ($conjuntos as $c) {
            $k = $fila($id($c->m));
            $filas[$k]['conjuntos'] += (int) $c->n;
            $filas[$k]['punto_conjuntos'] = $this->punto($c->lat, $c->lng);
        }

        // Los vecinos registrados en los conjuntos activos de cada ciudad.
        $residentes = DB::table('buyer_complex as bc')
            ->join('residential_complexes as c', 'c.complex_id', '=', 'bc.complex_id')
            ->where('c.state', 1)
            ->when($f['municipality_id'], fn ($q, $m) => $q->where('c.municipality_id', $m))
            ->selectRaw('c.municipality_id as m, COUNT(DISTINCT bc.buyer_id) as n')
            ->groupBy('c.municipality_id')
            ->get();

        foreach ($residentes as $r) {
            $filas[$fila($id($r->m))]['residentes'] += (int) $r->n;
        }

        $pedidos = $this->entregados($f, $v['desde'], $v['hasta'], true)
            ->selectRaw('b.municipality_id as m, COUNT(*) as n')
            ->groupBy('b.municipality_id')
            ->get();

        foreach ($pedidos as $p) {
            $k = $fila($id($p->m));
            $filas[$k]['pedidos'] += (int) $p->n;
        }

        $compradores = DB::table('user_address as ua')
            ->join('user as u', 'u.user_id', '=', 'ua.user_id')
            ->where('u.rol', self::ROL_COMPRADOR)
            ->where('ua.state', 1)
            ->whereNotNull('ua.municipality_id')
            ->when($f['municipality_id'], fn ($q, $m) => $q->where('ua.municipality_id', $m))
            ->selectRaw('ua.municipality_id as m, COUNT(DISTINCT ua.user_id) as n')
            ->groupBy('ua.municipality_id')
            ->get();

        foreach ($compradores as $c) {
            $filas[$fila((int) $c->m)]['compradores'] += (int) $c->n;
        }

        /*
         * Los compradores sin ciudad son los que no tienen ninguna dirección
         * con municipio. Solo se cuentan cuando no se filtra por ciudad: con
         * el filtro puesto, la fila «Sin ciudad» no tiene sentido.
         */
        if (! $f['municipality_id']) {
            $sinCiudad = DB::table('user as u')
                ->where('u.rol', self::ROL_COMPRADOR)
                ->whereNotExists(fn ($q) => $q->from('user_address as ua')
                    ->whereColumn('ua.user_id', 'u.user_id')
                    ->where('ua.state', 1)
                    ->whereNotNull('ua.municipality_id')
                    ->selectRaw('1'))
                ->count();

            if ($sinCiudad > 0) {
                $filas[$fila(null)]['compradores'] += $sinCiudad;
            }
        } else {
            unset($filas['sin']);
        }

        $ids = array_filter(array_column($filas, 'municipality_id'));
        $nombres = DB::table('municipalities as m')
            ->leftJoin('departments as d', 'd.id', '=', 'm.department_id')
            ->whereIn('m.id', $ids ?: [0])
            ->get(['m.id', 'm.name as ciudad', 'd.name as departamento'])
            ->keyBy('id');

        $lista = array_map(function (array $r) use ($nombres) {
            $sin = $r['municipality_id'] === null;
            $n = $sin ? null : ($nombres[$r['municipality_id']] ?? null);

            $porTipo = array_values($r['por_tipo']);
            usort($porTipo, fn ($a, $b) => $b['negocios'] <=> $a['negocios']);

            return [
                'municipality_id' => $r['municipality_id'],
                'ciudad' => $sin ? 'Sin ciudad' : ($n->ciudad ?? 'Ciudad desconocida'),
                'departamento' => $n->departamento ?? null,
                'negocios' => $r['negocios'],
                'por_tipo' => $porTipo,
                'conjuntos' => $r['conjuntos'],
                'residentes' => $r['residentes'],
                'pedidos' => $r['pedidos'],
                'compradores' => $r['compradores'],
                /*
                 * ESPERANDO: una ciudad con demanda (compradores con dirección
                 * ahí o conjuntos activos) y ningún negocio activo. «Sin
                 * ciudad» nunca espera: no es una ciudad.
                 */
                'esperando' => ! $sin && $r['negocios'] === 0 && ($r['compradores'] > 0 || $r['conjuntos'] > 0),
                // La cobertura: cuántos compradores le tocan a cada negocio.
                'compradores_por_negocio' => $this->cociente($r['compradores'], $r['negocios']),
                'desde' => $r['desde'] ? Carbon::parse($r['desde'])->toDateString() : null,
                // «Sin ciudad» no tiene dónde caer en el mapa.
                'punto' => $sin ? null : ($r['punto'] ?? $r['punto_conjuntos']),
            ];
        }, array_values($filas));

        // Las que más pesan primero; «Sin ciudad» siempre al final.
        usort($lista, fn ($a, $b) => [$a['municipality_id'] === null, -$a['negocios'], -$a['pedidos'], -$a['compradores'], $a['ciudad']]
            <=> [$b['municipality_id'] === null, -$b['negocios'], -$b['pedidos'], -$b['compradores'], $b['ciudad']]);

        return $lista;
    }

    /** @return array{lat: float, lng: float}|null */
    private function punto(mixed $lat, mixed $lng): ?array
    {
        if ($lat === null || $lng === null || ((float) $lat === 0.0 && (float) $lng === 0.0)) {
            return null;
        }

        return ['lat' => round((float) $lat, 2), 'lng' => round((float) $lng, 2)];
    }
}
