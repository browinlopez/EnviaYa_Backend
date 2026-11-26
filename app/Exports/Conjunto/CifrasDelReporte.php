<?php

declare(strict_types=1);

namespace App\Exports\Conjunto;

use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Las cifras del Excel, sacadas de lo que YA calculó `PulsoDelConjunto`.
 *
 * Todo sale de `libro`, que es exactamente lo que pinta la pantalla de
 * Reportes. Aquí no hay una sola consulta: si el Excel calculara algo por su
 * cuenta, el día que no cuadre con la pantalla nadie sabría cuál está mal.
 *
 * Lo que la pantalla deriva en el navegador (el % por QR, el cambio frente al
 * periodo anterior, el promedio, el día más movido) se deriva aquí con LA
 * MISMA regla, para que las dos digan lo mismo con las mismas cifras.
 *
 * Ningún nombre de residente entra a esta clase: `libro` no los trae.
 */
final class CifrasDelReporte
{
    private const MESES = ['ene', 'feb', 'mar', 'abr', 'may', 'jun', 'jul', 'ago', 'sep', 'oct', 'nov', 'dic'];

    private const DIAS = ['domingo', 'lunes', 'martes', 'miércoles', 'jueves', 'viernes', 'sábado'];

    public readonly string $conjunto;

    public readonly CarbonImmutable $desde;

    public readonly CarbonImmutable $hasta;

    public readonly CarbonImmutable $generado;

    public readonly int $entradas;

    public readonly int $entradasAnterior;

    /** Variación frente al periodo anterior (0.12 = +12 %), o null si no hay con qué comparar. */
    public readonly ?float $cambio;

    public readonly int $pedidosRecibidos;

    public readonly int $visitantes;

    public readonly int $porCodigo;

    public readonly int $porCedula;

    public readonly int $anotadas;

    public readonly int $todas;

    /** Parte de TODAS las entradas que entró por QR, de 0 a 1; null sin entradas. */
    public readonly ?float $parteQr;

    /** @var list<array{dia: string, etiqueta: string, entradas: int, pedidos: int}> */
    public readonly array $porDia;

    /** Pedidos al conjunto en el periodo, sumando la serie por día. */
    public readonly int $pedidosDelPeriodo;

    public readonly float $promedio;

    /** @var array{dia: string, etiqueta: string, entradas: int}|null */
    public readonly ?array $diaPico;

    /** @var list<array{torre: string, pedidos: int}> */
    public readonly array $torres;

    public readonly int $pedidosEnTorres;

    /** @var list<array{nombre: string, entradas: int, por_qr: float, minutos: ?int}> */
    public readonly array $domiciliarios;

    /** @var list<array{nombre: string, activo: bool, entradas: int, por_qr: ?float, salidas: int, ultima_actividad: ?string}> */
    public readonly array $celadores;

    public function __construct(array $datos, string $conjunto, ?CarbonImmutable $generado = null)
    {
        $l = $datos['libro'];

        $this->conjunto = $conjunto;
        $this->desde = CarbonImmutable::parse($datos['periodo']['desde']);
        $this->hasta = CarbonImmutable::parse($datos['periodo']['hasta']);
        $this->generado = $generado ?? CarbonImmutable::now();

        $this->entradas = (int) $l['entradas'];
        $this->entradasAnterior = (int) $l['entradas_anterior'];

        /*
         * La regla de la pantalla: sólo se compara si el periodo anterior
         * tiene con qué. «+3.453 %» contra un periodo casi vacío no es un
         * crecimiento, es que antes no había datos.
         */
        $comparable = $this->entradasAnterior >= max(20, $this->entradas * 0.1);
        $this->cambio = $comparable
            ? ($this->entradas - $this->entradasAnterior) / $this->entradasAnterior
            : null;

        $this->pedidosRecibidos = (int) $l['pedidos_recibidos'];
        $this->visitantes = (int) $l['visitantes'];

        $this->porCodigo = (int) $l['identificacion']['codigo'];
        $this->porCedula = (int) $l['identificacion']['cedula'];
        $this->anotadas = (int) $l['identificacion']['manual'];
        $this->todas = $this->porCodigo + $this->porCedula + $this->anotadas;
        $this->parteQr = $this->todas ? $this->porCodigo / $this->todas : null;

        $this->porDia = array_map(fn ($d) => [
            'dia' => (string) $d['dia'],
            'etiqueta' => (string) $d['etiqueta'],
            'entradas' => (int) $d['entradas'],
            'pedidos' => (int) ($d['pedidos'] ?? 0),
        ], array_values(self::lista($l['por_dia'])));
        $this->pedidosDelPeriodo = array_sum(array_column($this->porDia, 'pedidos'));

        $this->promedio = $this->entradas / max(count($this->porDia), 1);

        // El primero de los más altos, como la barra coral de la pantalla.
        $pico = null;
        foreach ($this->porDia as $d) {
            if ($d['entradas'] > 0 && ($pico === null || $d['entradas'] > $pico['entradas'])) {
                $pico = $d;
            }
        }
        $this->diaPico = $pico;

        $this->torres = array_map(fn ($t) => [
            'torre' => (string) $t['torre'],
            'pedidos' => (int) $t['pedidos'],
        ], array_values(self::lista($l['por_torre'])));
        $this->pedidosEnTorres = array_sum(array_column($this->torres, 'pedidos'));

        $this->domiciliarios = array_map(fn ($d) => [
            'nombre' => (string) ($d['nombre'] ?? 'Sin nombre'),
            'entradas' => (int) $d['entradas'],
            'por_qr' => (float) $d['por_qr'],
            'minutos' => $d['minutos'] === null ? null : (int) $d['minutos'],
        ], array_values(self::lista($l['domiciliarios'])));

        $this->celadores = array_map(fn ($c) => [
            'nombre' => (string) ($c['nombre'] ?? 'Sin nombre'),
            'activo' => (bool) ($c['activo'] ?? true),
            'entradas' => (int) $c['entradas'],
            'por_qr' => $c['por_qr'] === null ? null : (float) $c['por_qr'],
            'salidas' => (int) $c['salidas'],
            'ultima_actividad' => $c['ultima_actividad'] ?? null,
        ], array_values(self::lista($l['por_celador'] ?? [])));
    }

    /** El servicio devuelve colecciones; por JSON llegan como arreglos. Se aceptan las dos. */
    private static function lista(mixed $valor): array
    {
        $arreglo = $valor instanceof Collection ? $valor->all() : (array) $valor;

        return array_map(fn ($f) => (array) $f, $arreglo);
    }

    /* ------------------------------ FECHAS ------------------------------ */

    /*
     * Las fechas que se LEEN (títulos, notas) se escriben como texto en
     * español con meses propios: el formato de número de Excel depende del
     * idioma del equipo que abre el archivo y «Oct» en un reporte en español
     * se ve descuidado.
     */

    /** «28 oct 2026». */
    public static function fecha(CarbonImmutable $d): string
    {
        return $d->day.' '.self::MESES[$d->month - 1].' '.$d->year;
    }

    /** «28 oct». */
    public static function fechaCorta(CarbonImmutable $d): string
    {
        return $d->day.' '.self::MESES[$d->month - 1];
    }

    /** «miércoles». */
    public static function diaDeLaSemana(CarbonImmutable $d): string
    {
        return self::DIAS[$d->dayOfWeek];
    }

    /** «28 oct 2026 – 26 nov 2026». */
    public function periodo(): string
    {
        return self::fecha($this->desde).' – '.self::fecha($this->hasta);
    }

    public function dias(): int
    {
        return count($this->porDia);
    }

    /** «Generado el 26 nov 2026 a las 10:32». */
    public function generadoEl(): string
    {
        return 'Generado el '.self::fecha($this->generado).' a las '.$this->generado->format('H:i');
    }
}
