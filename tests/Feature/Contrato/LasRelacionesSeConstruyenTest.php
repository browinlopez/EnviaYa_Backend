<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\Relation;

/**
 * TODA RELACIÓN DECLARADA TIENE QUE PODER CONSTRUIRSE.
 *
 * Esta prueba nace de una rota de verdad. `Domiciliary::geolocation()` hacía
 * `hasMany(OrderGeolocation::class, ...)` SIN el `use`, así que el nombre se
 * resolvía contra `App\Models\` y salía `App\Models\OrderGeolocation`, que no
 * existe —la clase vive en `App\Models\Order\`—. Llamarla reventaba con
 * «Class "App\Models\OrderGeolocation" not found».
 *
 * POR QUÉ NO LO VIO NADIE, que es la parte que importa:
 *
 *   · `::class` NO comprueba que la clase exista. Es una cadena que el
 *     compilador arma con el espacio de nombres y ya está. Por eso `php -l`
 *     pasa, Pint pasa y la suite pasaba.
 *   · No la llama nadie. La torre de control lee `order_geolocation` por
 *     `DB::table`, así que la relación llevaba ahí rota sin molestar.
 *
 * Es el mismo molde que los guiones que arrancaban mal por un `use` colocado
 * debajo —ya pasó tres veces— y que vigila `LosGuionesArrancanTest`: un error
 * que solo aparece al EJECUTAR la línea, en código que nadie ejecuta.
 *
 * Lo que hace esta prueba es ejecutarlas todas, una vez, sin tocar la base:
 * construir la relación obliga a instanciar el modelo relacionado.
 */

/** @return array<int, array{0: string, 1: string}> */
function todosLosMetodosDeRelacion(): array
{
    $raiz = base_path('app/Models');
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($raiz, FilesystemIterator::SKIP_DOTS)
    );

    $casos = [];

    foreach ($it as $fichero) {
        if ($fichero->getExtension() !== 'php') {
            continue;
        }

        $fuente = file_get_contents($fichero->getPathname());

        if (! preg_match('/namespace\s+([^;]+);/', $fuente, $ns)
            || ! preg_match('/\bclass\s+(\w+)\s+extends/', $fuente, $cl)) {
            continue;
        }

        $clase = trim($ns[1]).'\\'.$cl[1];

        if (! class_exists($clase) || ! is_subclass_of($clase, Model::class)) {
            continue;
        }

        /* Solo los métodos que YA declaran devolver una relación. Son los que
           se pueden llamar a ciegas: sin argumentos y sin tocar la base. */
        foreach ((new ReflectionClass($clase))->getMethods(ReflectionMethod::IS_PUBLIC) as $m) {
            if ($m->getDeclaringClass()->getName() !== $clase
                || $m->getNumberOfParameters() !== 0) {
                continue;
            }

            $tipo = $m->getReturnType();

            if (! $tipo instanceof ReflectionNamedType
                || ! is_subclass_of($tipo->getName(), Relation::class)) {
                continue;
            }

            $casos[] = [$clase, $m->getName()];
        }
    }

    return $casos;
}

it('todas las relaciones declaradas se construyen sin reventar', function () {
    $casos = todosLosMetodosDeRelacion();

    /* CONTROL. Sin esto, la prueba pasa en verde con la lista vacía —que es
       exactamente lo que pasaría si el día de mañana cambia la forma de
       declarar las relaciones y el recorrido deja de encontrarlas—. El número
       medido al escribirla era 152; se exige holgadamente menos para que no
       se ponga roja por añadir o quitar una. */
    expect(count($casos))->toBeGreaterThan(100);

    $rotas = [];

    foreach ($casos as [$clase, $metodo]) {
        try {
            (new $clase)->{$metodo}();
        } catch (Throwable $e) {
            $rotas[] = $clase.'::'.$metodo.'()  ->  '.$e->getMessage();
        }
    }

    expect($rotas)->toBe([]);
});

it('CONTROL: la prueba ve de verdad una relacion que apunta a la nada', function () {
    /* La mitad que prueba que lo de arriba no está ciego: un modelo con una
       relación al vacío, igual que estaba `geolocation()`. Si construir una
       relación rota NO lanzara, esto pasaría y la prueba de arriba no serviría
       para nada. */
    $roto = new class extends Model
    {
        public function alVacio(): HasMany
        {
            /* @phpstan-ignore-next-line argument.type (es el punto de la prueba) */
            return $this->hasMany('App\Models\ClaseQueNoExiste');
        }
    };

    /* `Error::class` y no `Throwable::class`: `Throwable` es una INTERFAZ, y
       `toThrow` con una cadena que no es una clase existente la toma por el
       mensaje de la excepción, no por su tipo. La primera versión de esta
       línea pasaba por ahí y comparaba «Throwable» contra «Class ... not
       found». Es el mismo molde que `toContain('x', 'mensaje')`. */
    expect(fn () => $roto->alVacio())->toThrow(Error::class);
});

it('ninguna relacion se queda sin tipo de retorno', function () {
    /*
     * ESTO ES LO QUE CIERRA EL CÍRCULO, y sin ello lo de arriba se deshace
     * solo: la prueba que construye las relaciones solo mira las que YA
     * declaran devolver una `Relation`. Una relación nueva sin tipo no la
     * verificaría nadie —ni esa prueba, ni Larastan, que es justo por lo que
     * `geolocation()` llevaba rota tanto tiempo—.
     *
     * Y hay una segunda razón, medida: tipar las 100 que faltaban bajó
     * PHPStan de 157 avisos a 60. Sin esta prueba, ese número vuelve a subir
     * una relación cada vez, sin que nadie se entere.
     */
    $raiz = base_path('app/Models');
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($raiz, FilesystemIterator::SKIP_DOTS)
    );

    $tipos = 'belongsToMany|belongsTo|hasManyThrough|hasOneThrough|hasMany|hasOne'
        .'|morphMany|morphOne|morphToMany|morphTo';
    $barra = chr(92);
    $rx = '~public function (\w+)\(\)(\s*:\s*[\w'.$barra.$barra.'|]+)?\s*\{'
        .'\s*return \$this->('.$tipos.')\(~';

    $sinTipo = [];
    $conTipo = 0;

    foreach ($it as $fichero) {
        if ($fichero->getExtension() !== 'php') {
            continue;
        }

        preg_match_all($rx, file_get_contents($fichero->getPathname()), $m, PREG_SET_ORDER);

        foreach ($m as $x) {
            if (trim($x[2]) === '') {
                $sinTipo[] = basename($fichero->getPathname()).'::'.$x[1].'()  '.$x[3];
            } else {
                $conTipo++;
            }
        }
    }

    /* CONTROL, y es la mitad que importa: si el patrón dejara de reconocer
       los tipos, vería cero tipadas y la lista de «sin tipo» saldría vacía
       por el otro lado —la prueba pasaría estando ciega—. Eran 152 al
       escribirla. */
    expect($conTipo)->toBeGreaterThan(100);

    expect($sinTipo)->toBe([]);
});
