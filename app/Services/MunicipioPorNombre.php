<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * De "Barranquilla" al municipio que tenemos en la base.
 *
 * Las direcciones creadas desde la app no traían municipio: el campo estaba
 * comentado en el controlador y la app nunca lo mandó, así que TODAS quedaban
 * con `municipality_id` nulo. La dirección de facturación que se le manda a la
 * pasarela salía sin ciudad ni departamento, y cualquier consulta por municipio
 * —cobertura, informes por zona— dejaba fuera a esas direcciones.
 *
 * El dato existía y se tiraba: el teléfono ya resuelve las coordenadas a
 * ciudad y departamento para escribir la dirección en pantalla. Ahora se
 * manda, y acá se traduce al identificador que usa la base.
 *
 * Se compara por NOMBRE y no por coordenadas porque la tabla no las tiene: son
 * 110 municipios con id, nombre y departamento, nada más.
 */
class MunicipioPorNombre
{
    /**
     * @param  string|null  $nombre        Ciudad que devolvió el teléfono.
     * @param  string|null  $departamento  Sirve para desempatar nombres repetidos.
     * @return int|null  Null si no se reconoce: la dirección se guarda igual.
     */
    public static function resolver(?string $nombre, ?string $departamento = null): ?int
    {
        $buscado = self::normalizar($nombre);

        if ($buscado === '') {
            return null;
        }

        $candidatos = DB::table('municipalities as m')
            ->leftJoin('departments as d', 'd.id', '=', 'm.department_id')
            ->select('m.id', 'm.name', 'd.name as departamento')
            ->get()
            ->filter(fn ($m) => self::normalizar($m->name) === $buscado);

        if ($candidatos->isEmpty()) {
            return null;
        }

        if ($candidatos->count() === 1) {
            return (int) $candidatos->first()->id;
        }

        /*
         * Hay municipios que se llaman igual en departamentos distintos. Si el
         * teléfono dijo de cuál, se usa; si no, se devuelve null en vez de
         * elegir uno al azar: una dirección sin municipio se puede completar
         * después, una con el municipio equivocado manda al domiciliario a
         * otra parte.
         */
        $porDepartamento = $candidatos->first(
            fn ($m) => self::normalizar($m->departamento) === self::normalizar($departamento),
        );

        return $porDepartamento ? (int) $porDepartamento->id : null;
    }

    /**
     * Sin tildes, sin mayúsculas y sin lo que sobra alrededor.
     *
     * El teléfono devuelve "Barranquilla" y la base puede tener "BARRANQUILLA";
     * en otros casos aparecen "Bogotá" y "Bogota". Comparar en crudo dejaría
     * sin municipio a direcciones perfectamente reconocibles.
     */
    private static function normalizar(?string $texto): string
    {
        $texto = trim((string) $texto);

        if ($texto === '') {
            return '';
        }

        $sinTildes = strtr($texto, [
            'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u',
            'Á' => 'a', 'É' => 'e', 'Í' => 'i', 'Ó' => 'o', 'Ú' => 'u', 'Ü' => 'u',
            'ñ' => 'n', 'Ñ' => 'n',
        ]);

        return mb_strtolower($sinTildes, 'UTF-8');
    }
}
