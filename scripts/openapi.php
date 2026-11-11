<?php

declare(strict_types=1);

/*
 * GENERA EL CONTRATO OpenAPI DE `/v1` DESDE EL ROUTER — §34.
 *
 *     php scripts/openapi.php            # escribe docs/laravel-12/openapi.json
 *     php scripts/openapi.php --revisar  # no escribe; sale 1 si el fichero no esta al dia
 *
 * POR QUE GENERADO Y NO ESCRITO A MANO. §34 pide «el contrato como codigo» y
 * mantener la compatibilidad de los consumidores. Un fichero OpenAPI escrito a
 * mano empieza siendo verdad y deja de serlo con el primer cambio de ruta que
 * nadie refleja; a partir de ahi es peor que no tenerlo, porque alguien lo lee
 * y le cree. Generarlo del router significa que no puede mentir sobre que
 * rutas existen, con que verbo y detras de que middleware.
 *
 * DE DONDE SALE CADA COSA, Y QUE NO SE INVENTA:
 *
 *   rutas, verbos, nombres   del router. Exacto.
 *   seguridad                del middleware de cada ruta: `auth:sanctum`
 *                            significa Bearer. Aqui la autorizacion va por
 *                            middleware y no por Policy —es una excepcion
 *                            decidida, ver EXCEPCIONES.md— asi que la lista
 *                            de middleware ES el contrato de permisos y viaja
 *                            en `x-middleware`.
 *   parametros de ruta       de la propia URI.
 *   cuerpo de la peticion    de las reglas del `FormRequest` que la firma del
 *                            metodo declare. Son 111 de 294 rutas.
 *   respuestas               de `tests/Feature/Contrato/forma-de-las-respuestas.json`
 *                            donde la haya (3 endpoints), y de los codigos
 *                            que el middleware hace posibles.
 *
 * LAS 183 RUTAS SIN `FormRequest` SALEN CON EL CUERPO SIN DOCUMENTAR, y
 * marcadas con `x-cuerpo: "no documentado"`. Eso es a proposito: validan en
 * linea —41 casos con su motivo escrito, ver EXCEPCIONES.md— y leer esas
 * reglas de dentro del metodo pediria ejecutar el controlador. Un esquema
 * inventado para rellenar el hueco seria una afirmacion falsa sobre lo que la
 * API acepta, y este fichero existe para que se le pueda creer.
 */

/*
 * LOS `use` VAN ARRIBA, ANTES DEL ARRANQUE, y no es estilo: es que si no, el
 * guion no corre.
 *
 * Pint acorta `Illuminate\Contracts\Console\Kernel::class` a `Kernel::class`
 * apoyandose en el `use`, y si el `use` esta DEBAJO de la linea que lo usa,
 * PHP resuelve `Kernel` como clase global y revienta con «Class "Kernel" does
 * not exist». `php -l` pasa, porque la sintaxis es correcta.
 *
 * Ya paso en `scripts/percentiles_de_latencia.php` en la misma semana. Es la
 * trampa de estos guiones sueltos: mezclan `require` con `use` y el orden
 * importa de una manera que no importa en una clase normal.
 */

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Route;

$raiz = dirname(__DIR__);

require $raiz.'/vendor/autoload.php';

$app = require $raiz.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$revisar = in_array('--revisar', $argv, true);
$destino = $raiz.'/docs/laravel-12/openapi.json';

/** El tipo de OpenAPI que corresponde a unas reglas de validacion. */
function tipoDeLaRegla(mixed $reglas): array
{
    $texto = is_array($reglas)
        ? implode('|', array_map(fn ($r) => is_string($r) ? $r : '', $reglas))
        : (string) $reglas;

    $tiene = fn (string $r) => (bool) preg_match('/\b'.preg_quote($r, '/').'\b/', $texto);

    /*
     * EL ORDEN IMPORTA. `integer` antes que `numeric` porque una regla puede
     * llevar las dos y la mas estrecha es la que describe el dato; `file`
     * antes que `string` porque una subida lleva `mimes` y `max` que parecen
     * de texto.
     */
    if ($tiene('file') || $tiene('image')) {
        return ['type' => 'string', 'format' => 'binary'];
    }
    if ($tiene('boolean')) {
        return ['type' => 'boolean'];
    }
    if ($tiene('integer')) {
        return ['type' => 'integer'];
    }
    if ($tiene('numeric') || $tiene('decimal')) {
        return ['type' => 'number'];
    }
    if ($tiene('array')) {
        return ['type' => 'array', 'items' => ['type' => 'string']];
    }
    if ($tiene('date') || $tiene('date_format')) {
        return ['type' => 'string', 'format' => 'date-time'];
    }
    if ($tiene('email')) {
        return ['type' => 'string', 'format' => 'email'];
    }

    $esquema = ['type' => 'string'];

    // `in:a,b,c` es una enumeracion de verdad y vale documentarla.
    if (preg_match('/\bin:([^|]+)/', $texto, $m)) {
        $esquema['enum'] = array_values(array_filter(explode(',', $m[1]), fn ($v) => $v !== ''));
    }

    return $esquema;
}

/** Las reglas del FormRequest que declare la firma del metodo, si hay uno. */
function reglasDeLaRuta(string $accion): ?array
{
    if (! str_contains($accion, '@')) {
        return null;
    }

    [$clase, $metodo] = explode('@', $accion, 2);

    try {
        $rm = new ReflectionMethod($clase, $metodo);
    } catch (Throwable) {
        return null;
    }

    foreach ($rm->getParameters() as $p) {
        $t = $p->getType();

        if (! $t instanceof ReflectionNamedType || $t->isBuiltin()) {
            continue;
        }

        $nombre = $t->getName();

        if (! is_subclass_of($nombre, FormRequest::class)) {
            continue;
        }

        /*
         * `rules()` SE LLAMA DENTRO DE UN try, y no por prudencia generica:
         * varias de estas clases leen el ajuste del tope de caracteres o la
         * ruta para decidir una regla, y fuera de una peticion eso puede
         * reventar. Cuando revienta se devuelve null y la ruta sale con el
         * cuerpo sin documentar, que es la verdad.
         */
        try {
            $instancia = new $nombre;
            $reglas = $instancia->rules();

            return is_array($reglas) ? ['clase' => $nombre, 'reglas' => $reglas] : null;
        } catch (Throwable) {
            return null;
        }
    }

    return null;
}

/** La forma de respuesta congelada, si esta ruta tiene una. */
function respuestaCongelada(string $verbo, string $uri): ?array
{
    static $todo = null;

    if ($todo === null) {
        $f = dirname(__DIR__).'/tests/Feature/Contrato/forma-de-las-respuestas.json';
        $todo = is_file($f) ? (array) json_decode((string) file_get_contents($f), true) : [];
    }

    $clave = $verbo.' /'.$uri;

    if (! isset($todo[$clave])) {
        return null;
    }

    /*
     * EL ESTADO SE LEE, NO SE DEDUCE DEL VERBO. La primera version ponia 201
     * a todo POST, y `POST /v1/orders/business` es un POST que devuelve 200
     * —es una LECTURA que manda el filtro en el cuerpo—. O sea que el
     * contrato afirmaba un codigo que nadie habia medido. Lo encontro la
     * prueba de punta a punta de ContratoOpenApiTest, y por eso la forma
     * congelada guarda ahora el estado al lado.
     */
    return [
        'estado' => (int) ($todo[$clave]['estado'] ?? 200),
        'forma' => (array) ($todo[$clave]['forma'] ?? []),
    ];
}

/** De `{"order.total": "string"}` a un esquema anidado de OpenAPI. */
function esquemaDesdeLaForma(array $plano): array
{
    $arbol = [];

    foreach ($plano as $camino => $tipo) {
        $partes = preg_split('/\./', (string) $camino) ?: [];
        $nodo = &$arbol;

        foreach ($partes as $i => $parte) {
            $lista = str_ends_with($parte, '[]');
            $parte = $lista ? substr($parte, 0, -2) : $parte;

            if ($parte === '') {
                continue;
            }

            if ($i === count($partes) - 1) {
                $nodo[$parte] = ['__tipo' => $tipo, '__lista' => $lista];

                continue;
            }

            $nodo[$parte] ??= ['__lista' => $lista];
            $nodo[$parte]['__hijos'] ??= [];
            $nodo = &$nodo[$parte]['__hijos'];
        }

        unset($nodo);
    }

    return aObjeto($arbol);
}

function aObjeto(array $nodos): array
{
    $props = [];

    foreach ($nodos as $nombre => $n) {
        if (isset($n['__hijos'])) {
            $dentro = aObjeto($n['__hijos']);
        } else {
            $dentro = ['type' => match ($n['__tipo'] ?? 'string') {
                'int' => 'integer',
                'float' => 'number',
                'bool' => 'boolean',
                'array' => 'object',
                'null' => 'string',
                default => 'string',
            }];

            // `null` se documenta como anulable y no se calla: hay claves que
            // viajan siempre nulas y las apps publicadas las leen.
            if (($n['__tipo'] ?? '') === 'null') {
                $dentro['nullable'] = true;
            }
        }

        $props[$nombre] = ($n['__lista'] ?? false)
            ? ['type' => 'array', 'items' => $dentro]
            : $dentro;
    }

    return ['type' => 'object', 'properties' => $props];
}

/* ------------------------------------------------------------------ */

$rutas = [];

foreach (Route::getRoutes() as $r) {
    $uri = $r->uri();

    if (! str_starts_with($uri, 'v1/')) {
        continue;
    }

    foreach (array_diff($r->methods(), ['HEAD']) as $verbo) {
        $rutas[] = [$verbo, $uri, $r];
    }
}

usort($rutas, fn ($a, $b) => [$a[1], $a[0]] <=> [$b[1], $b[0]]);

$paths = [];
$conCuerpo = 0;
$sinCuerpo = 0;
$conRespuesta = 0;

foreach ($rutas as [$verbo, $uri, $r]) {
    $ruta = '/'.$uri;
    $accion = (string) ($r->getAction('controller') ?? '');

    $middleware = $r->gatherMiddleware();
    sort($middleware);
    $pideSesion = (bool) array_filter(
        $middleware,
        fn ($m) => is_string($m) && str_contains($m, 'auth:sanctum'),
    );

    $op = [
        'operationId' => $r->getName() ?: strtolower($verbo).'_'.preg_replace('/[^a-zA-Z0-9]+/', '_', $uri),
        'tags' => [explode('/', $uri)[1] ?? 'v1'],
        'summary' => $accion !== '' ? $accion : 'closure',
        'x-middleware' => array_values($middleware),
        'security' => $pideSesion ? [['bearerAuth' => []]] : [],
        'responses' => [],
    ];

    // Parametros de la URI.
    $parametros = [];

    if (preg_match_all('/\{(\w+)\??\}/', $uri, $m)) {
        foreach ($m[1] as $nombre) {
            $parametros[] = [
                'name' => $nombre,
                'in' => 'path',
                'required' => true,
                'schema' => ['type' => 'string'],
            ];
        }
    }

    if ($parametros !== []) {
        $op['parameters'] = $parametros;
    }

    // Cuerpo, solo si hay un FormRequest de donde leerlo.
    $req = reglasDeLaRuta($accion);

    if ($req !== null && in_array($verbo, ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
        $props = [];
        $obligatorios = [];

        foreach ($req['reglas'] as $campo => $reglas) {
            $campo = (string) $campo;
            $texto = is_array($reglas) ? implode('|', array_map(fn ($x) => is_string($x) ? $x : '', $reglas)) : (string) $reglas;

            // `products.*.amount` describe los elementos de un array; se
            // documenta el array y no una clave con puntos dentro.
            $raiz = explode('.', $campo)[0];
            $props[$raiz] ??= str_contains($campo, '.')
                ? ['type' => 'array', 'items' => ['type' => 'object']]
                : tipoDeLaRegla($reglas);

            if (! str_contains($campo, '.') && preg_match('/\brequired\b/', $texto)) {
                $obligatorios[] = $campo;
            }
        }

        $esquema = ['type' => 'object', 'properties' => $props];

        if ($obligatorios !== []) {
            $esquema['required'] = array_values(array_unique($obligatorios));
        }

        $op['requestBody'] = [
            'required' => $obligatorios !== [],
            'content' => ['application/json' => ['schema' => $esquema]],
        ];
        $op['x-form-request'] = $req['clase'];
        $conCuerpo++;
    } elseif (in_array($verbo, ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
        /*
         * DELETE TAMBIEN, y no es un detalle: en esta API varios DELETE
         * llevan cuerpo —`DELETE /v1/devices` manda el token del dispositivo
         * a borrar—. Dejarlos sin marcar hacia que el contrato IMPLICARA que
         * no aceptan cuerpo, que es una afirmacion distinta de «no se sabe».
         */
        $op['x-cuerpo'] = 'no documentado: valida en linea, ver EXCEPCIONES.md';
        $sinCuerpo++;
    }

    // Respuestas: la congelada si existe, y los codigos que el middleware
    // hace posibles.
    $forma = respuestaCongelada($verbo, $uri);

    if ($forma !== null) {
        $codigo = (string) $forma['estado'];
        $op['responses'][$codigo] = [
            'description' => 'Forma congelada en tests/Feature/Contrato/forma-de-las-respuestas.json',
            'content' => ['application/json' => ['schema' => esquemaDesdeLaForma($forma['forma'])]],
        ];
        $conRespuesta++;
    } else {
        $op['responses']['200'] = [
            'description' => 'Respuesta correcta. La forma no esta congelada todavia: ver §34 en ESTADO.md.',
        ];
    }

    if ($pideSesion) {
        $op['responses']['401'] = ['description' => 'Sin token, o token invalido.'];
    }

    if (array_filter($middleware, fn ($m) => is_string($m) && (
        str_contains($m, 'negocio') || str_contains($m, 'admin') || str_contains($m, 'modulo')
        || str_contains($m, 'conjunto') || str_contains($m, 'medios') || str_contains($m, 'categorias')
    ))) {
        $op['responses']['403'] = ['description' => 'La sesion no alcanza este recurso.'];
    }

    if (isset($op['requestBody']) || isset($op['x-cuerpo'])) {
        $op['responses']['422'] = ['description' => 'Validacion fallida.'];
    }

    $paths[$ruta] ??= [];
    $paths[$ruta][strtolower($verbo)] = $op;
}

$spec = [
    'openapi' => '3.1.0',
    'info' => [
        'title' => 'EnviaYa / VeciPaYa — API v1',
        'version' => '1.0.0',
        'description' => implode("\n", [
            'GENERADO, NO ESCRITO A MANO. Lo produce `php scripts/openapi.php`',
            'desde el router, y el CI comprueba que esta al dia con `--revisar`.',
            '',
            'Lo que este fichero afirma con certeza: que rutas existen, con que',
            'verbo, detras de que middleware y con que parametros de ruta.',
            '',
            'Lo que afirma a medias y lo dice: el cuerpo de la peticion solo',
            'esta documentado en las rutas que declaran un FormRequest; las',
            'demas validan en linea y salen con `x-cuerpo: no documentado` en',
            'vez de con un esquema inventado.',
            '',
            'La autorizacion de esta API va por MIDDLEWARE y no por Policy —es',
            'una excepcion decidida, ver EXCEPCIONES.md— asi que la lista de',
            '`x-middleware` de cada operacion es el contrato de permisos.',
        ]),
    ],
    'servers' => [
        ['url' => 'https://api.vecipaya.com', 'description' => 'Produccion'],
        ['url' => 'http://127.0.0.1:8000', 'description' => 'Desarrollo'],
    ],
    'components' => [
        'securitySchemes' => [
            'bearerAuth' => [
                'type' => 'http',
                'scheme' => 'bearer',
                'description' => 'Token personal de Sanctum, con la forma `<id>|<secreto>`.',
            ],
        ],
    ],
    'paths' => $paths,
];

$json = json_encode(
    $spec,
    JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
)."\n";

if ($revisar) {
    $actual = is_file($destino) ? (string) file_get_contents($destino) : '';

    if ($actual === $json) {
        echo 'El contrato OpenAPI esta al dia.'.PHP_EOL;
        exit(0);
    }

    echo 'EL CONTRATO OpenAPI NO ESTA AL DIA.'.PHP_EOL;
    echo 'Cambio la superficie de la API y el fichero no se regenero.'.PHP_EOL;
    echo PHP_EOL.'  php scripts/openapi.php'.PHP_EOL;
    exit(1);
}

file_put_contents($destino, $json);

printf(
    'escrito %s%s  %d operaciones, %d con cuerpo documentado, %d sin, %d con respuesta congelada%s',
    'docs/laravel-12/openapi.json',
    PHP_EOL,
    count($rutas),
    $conCuerpo,
    $sinCuerpo,
    $conRespuesta,
    PHP_EOL,
);
