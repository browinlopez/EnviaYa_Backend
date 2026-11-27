<?php

declare(strict_types=1);

// REGLAS.md §1: un comentario nuevo es una línea de ≤ 100 caracteres que dice el porqué.

const COMENTARIOS_MAX = 100;

const COMENTARIOS_CARPETAS = ['app/', 'bootstrap/', 'config/', 'database/', 'routes/', 'scripts/', 'tests/'];

/** @return list<array{texto: string, inicio: int, fin: int, linea: bool, solo: bool}> */
function comentariosDe(string $fuente): array
{
    $salida = [];
    $finDeLinea = true;

    foreach (token_get_all($fuente) as $pieza) {
        if (! is_array($pieza)) {
            $finDeLinea = false;

            continue;
        }

        [$tipo, $texto, $inicio] = $pieza;

        if ($tipo === T_WHITESPACE || $tipo === T_OPEN_TAG) {
            $finDeLinea = $finDeLinea || str_contains($texto, "\n") || $tipo === T_OPEN_TAG;

            continue;
        }

        if ($tipo === T_COMMENT || $tipo === T_DOC_COMMENT) {
            // PHP deja el salto de línea dentro del token de un //: no es parte del comentario.
            $limpio = rtrim($texto, "\r\n");
            $esLinea = ! str_starts_with($limpio, '/*');
            $salida[] = [
                'texto' => $limpio,
                'inicio' => $inicio,
                'fin' => $inicio + substr_count($limpio, "\n"),
                'linea' => $esLinea,
                'solo' => $finDeLinea,
            ];
            $finDeLinea = $esLinea;

            continue;
        }

        $finDeLinea = false;
    }

    return $salida;
}

function comentarioDeEtiquetas(string $texto): bool
{
    if (! str_starts_with($texto, '/**')) {
        return false;
    }

    $lineas = array_filter(array_map(
        fn (string $l) => trim((string) preg_replace('/^\s*\*?\s?/', '', $l)),
        explode("\n", substr($texto, 3, -2)),
    ));

    return $lineas !== [] && array_filter($lineas, fn (string $l) => ! str_starts_with($l, '@')) === [];
}

function comentarioDirectiva(string $texto): bool
{
    return (bool) preg_match('#^(//|/\*|\#)\s*(@phpstan-|@codeCoverageIgnore|phpcs:|@noinspection)#', $texto);
}

/**
 * @param  list<array{texto: string, inicio: int, fin: int, linea: bool, solo: bool}>  $comentarios
 * @param  array<int, true>  $agregadas
 * @return list<array{linea: int, motivo: string}>
 */
function infracciones(array $comentarios, array $agregadas): array
{
    $toca = function (array $c) use ($agregadas): bool {
        for ($l = $c['inicio']; $l <= $c['fin']; $l++) {
            if (isset($agregadas[$l])) {
                return true;
            }
        }

        return false;
    };

    $salida = [];

    foreach ($comentarios as $c) {
        if (! $toca($c) || comentarioDeEtiquetas($c['texto']) || comentarioDirectiva($c['texto'])) {
            continue;
        }

        $largo = mb_strlen(trim($c['texto']));

        if ($c['fin'] > $c['inicio']) {
            $salida[] = ['linea' => $c['inicio'], 'motivo' => 'ocupa '.($c['fin'] - $c['inicio'] + 1).' líneas'];
        } elseif ($largo > COMENTARIOS_MAX) {
            $salida[] = ['linea' => $c['inicio'], 'motivo' => "tiene {$largo} caracteres"];
        }
    }

    // Varios // seguidos, cada uno en su línea, son un párrafo aunque cada uno sea corto.
    $sueltos = array_values(array_filter($comentarios, fn ($c) => $c['linea'] && $c['solo']));

    for ($i = 0; $i < count($sueltos);) {
        $j = $i;

        while ($j + 1 < count($sueltos) && $sueltos[$j + 1]['inicio'] === $sueltos[$j]['fin'] + 1) {
            $j++;
        }

        $grupo = array_slice($sueltos, $i, $j - $i + 1);
        $nuevas = array_values(array_filter($grupo, $toca));

        if (count($grupo) > 1 && $nuevas !== []) {
            $salida[] = ['linea' => $nuevas[0]['inicio'], 'motivo' => count($grupo).' líneas // seguidas'];
        }

        $i = $j + 1;
    }

    usort($salida, fn ($a, $b) => $a['linea'] <=> $b['linea']);

    return $salida;
}

/** @return array<string, array<int, true>> */
function lineasAgregadas(string $diff): array
{
    $agregadas = [];
    $ruta = null;

    foreach (explode("\n", $diff) as $l) {
        if (str_starts_with($l, '+++ ')) {
            $ruta = $l === '+++ /dev/null' ? null : substr($l, 6);
        }

        if ($ruta === null || ! preg_match('/^@@ -\S+ \+(\d+)(?:,(\d+))? @@/', $l, $m)) {
            continue;
        }

        $cuantas = isset($m[2]) && $m[2] !== '' ? (int) $m[2] : 1;

        for ($n = 0; $n < $cuantas; $n++) {
            $agregadas[$ruta][(int) $m[1] + $n] = true;
        }
    }

    return $agregadas;
}

function comentariosPrincipal(array $argv): int
{
    chdir(dirname(__DIR__));

    $i = array_search('--base', $argv, true);
    $base = $i !== false ? (string) ($argv[$i + 1] ?? '') : (getenv('COMENTARIOS_BASE') ?: 'origin/Devs');
    $git = fn (string $args): string => (string) shell_exec('git -c core.quotePath=false '.$args.' 2>&1');

    $desde = trim($git('merge-base HEAD '.escapeshellarg($base)));

    if (! preg_match('/^[0-9a-f]{40}$/', $desde)) {
        fwrite(STDERR, "comentarios: no encuentro {$base}; no hay con qué comparar y no reviso nada.\n");

        return 0;
    }

    // Contra el árbol de trabajo: lo que aún no se confirmó también cuenta.
    $agregadas = lineasAgregadas($git('diff -U0 --no-color --src-prefix=a/ --dst-prefix=b/ --diff-filter=AMR '.$desde));

    foreach (array_filter(explode("\n", $git('ls-files --others --exclude-standard'))) as $ruta) {
        $agregadas[$ruta] = array_fill_keys(range(1, max(1, count(file($ruta) ?: []))), true);
    }

    $fallos = [];

    foreach ($agregadas as $ruta => $lineas) {
        $dentro = array_filter(COMENTARIOS_CARPETAS, fn ($c) => str_starts_with($ruta, $c));

        if (! str_ends_with($ruta, '.php') || $dentro === [] || ! is_file($ruta)) {
            continue;
        }

        $fuente = str_replace("\r\n", "\n", (string) file_get_contents($ruta));

        foreach (infracciones(comentariosDe($fuente), $lineas) as $f) {
            $fallos[] = "{$ruta}:{$f['linea']}  {$f['motivo']}";
        }
    }

    if ($fallos === []) {
        echo "Comentarios: los nuevos caben en una línea (contra {$base}).\n";

        return 0;
    }

    fwrite(STDERR, "Comentarios nuevos que no cumplen REGLAS.md §1 (una línea, ≤ 100, solo el porqué):\n");

    foreach ($fallos as $f) {
        fwrite(STDERR, "  {$f}\n");
    }

    return 1;
}

if (PHP_SAPI === 'cli' && realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    exit(comentariosPrincipal($argv));
}
