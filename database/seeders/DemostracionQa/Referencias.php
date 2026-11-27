<?php

declare(strict_types=1);

namespace Database\Seeders\DemostracionQa;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

// Catálogos que la demo usa y no crea: se buscan por nombre; si falta uno, no se siembra nada.
final class Referencias
{
    public const MUNICIPIOS = ['Barranquilla', 'Soledad', 'Malambo', 'Puerto Colombia', 'Galapa'];

    private const ROLES = [1, 2, 3, 5, 6];

    private const MEDIOS = [1, 2, 4, 5, 6, 7];

    private const FORMAS = [1, 2];

    /** @return array<string, int> municipio => id, del Atlántico */
    public static function municipios(): array
    {
        $ids = DB::table('municipalities as m')
            ->join('departments as d', 'd.id', '=', 'm.department_id')
            ->where('d.name', 'like', '%tl%ntico')
            ->whereIn('m.name', self::MUNICIPIOS)
            ->pluck('m.id', 'm.name')
            ->map(fn ($id) => (int) $id)
            ->all();

        self::exigir(array_diff(self::MUNICIPIOS, array_keys($ids)), 'municipios del Atlántico');

        return $ids;
    }

    /** @return array<string, int> oficio => id de category_business */
    public static function tipos(): array
    {
        $tipos = [];

        foreach (DB::table('category_business')->orderBy('id')->get(['id', 'name']) as $t) {
            $n = Str::lower(Str::ascii((string) $t->name));
            $oficio = match (true) {
                str_contains($n, 'repuest') => 'repuestos',
                str_contains($n, 'farmac'), str_contains($n, 'drog') => 'drogueria',
                str_contains($n, 'restaur') => 'restaurante',
                str_contains($n, 'tienda') => 'tienda',
                default => null,
            };

            if ($oficio !== null) {
                $tipos[$oficio] ??= (int) $t->id;
            }
        }

        self::exigir(array_diff(['tienda', 'drogueria', 'restaurante', 'repuestos'], array_keys($tipos)), 'tipos de negocio');

        return $tipos;
    }

    public static function comprobarCatalogos(): void
    {
        $faltan = fn (string $tabla, string $llave, array $ids) => array_diff($ids, DB::table($tabla)->whereIn($llave, $ids)->pluck($llave)->map(fn ($i) => (int) $i)->all());

        self::exigir($faltan('rol', 'rol_id', self::ROLES), 'roles');
        self::exigir($faltan('payment_methods', 'methods_id', self::MEDIOS), 'medios de pago');
        self::exigir($faltan('payment_forms', 'forms_id', self::FORMAS), 'formas de pago');
        self::exigir($faltan('document_types', 'id', [1]), 'tipos de documento');
    }

    /** @param array<int|string> $faltan */
    private static function exigir(array $faltan, string $que): void
    {
        if ($faltan !== []) {
            throw new RuntimeException("Faltan {$que} en la base: ".implode(', ', $faltan).'. No se sembró nada.');
        }
    }
}
