<?php

namespace App\Services;

use App\Models\Domiciliary;
use App\Models\Operacion\DomiciliaryDocument;

/**
 * LO QUE LE FALTA A UN DOMICILIARIO PARA PODER REPARTIR.
 *
 * Aplica a los que dio de alta una TIENDA (`registrado_por_negocio`): para
 * ellos el acuerdo de vinculación firmado y la cédula, la licencia y el SOAT
 * vigentes son condición para recibir pedidos. A los del equipo no se les
 * exige —ver la migración que agrega la columna—.
 *
 * Un papel cuenta si está vigente, por vencer o no tiene vencimiento; vencido
 * o ausente, no.
 */
class PapelesDelDomiciliario
{
    public const REQUERIDOS = ['cedula', 'licencia', 'soat'];

    private const ROTULOS = [
        'acuerdo'  => 'el acuerdo firmado',
        'cedula'   => 'la cédula',
        'licencia' => 'la licencia',
        'soat'     => 'el SOAT',
    ];

    /** Si la regla le aplica: lo creó una tienda. */
    public function leAplica(Domiciliary $d): bool
    {
        return $d->registrado_por_negocio !== null;
    }

    /** Las claves de lo que falta: 'acuerdo', 'cedula', 'licencia', 'soat'. */
    public function faltantes(Domiciliary $d): array
    {
        if (!$this->leAplica($d)) {
            return [];
        }

        $faltan = $d->contract_signed_at === null ? ['acuerdo'] : [];

        $vigentes = DomiciliaryDocument::where('domiciliary_id', $d->domiciliary_id)
            ->where('state', 1)
            ->whereIn('type', self::REQUERIDOS)
            ->get()
            ->filter(fn ($doc) => in_array($doc->situacion(), ['vigente', 'por_vencer', 'sin_vencimiento'], true))
            ->pluck('type')
            ->all();

        foreach (self::REQUERIDOS as $tipo) {
            if (!in_array($tipo, $vigentes, true)) {
                $faltan[] = $tipo;
            }
        }

        return $faltan;
    }

    /** «el acuerdo firmado, la licencia y el SOAT». */
    public function enPalabras(array $faltan): string
    {
        $t = array_map(fn ($k) => self::ROTULOS[$k] ?? $k, $faltan);

        if (count($t) <= 1) {
            return $t[0] ?? '';
        }

        return implode(', ', array_slice($t, 0, -1)) . ' y ' . end($t);
    }
}
