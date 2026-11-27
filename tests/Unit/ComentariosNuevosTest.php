<?php

declare(strict_types=1);

require_once __DIR__.'/../../scripts/comentarios.php';

function revisarComentarios(string $fuente, ?array $agregadas = null): array
{
    $agregadas ??= array_fill_keys(range(1, substr_count($fuente, "\n") + 1), true);

    return array_column(infracciones(comentariosDe($fuente), $agregadas), 'motivo');
}

it('una línea corta que dice el porqué pasa', function () {
    expect(revisarComentarios("<?php\n\n// El índice único decide, no el código.\n\$a = 1;\n"))->toBe([]);
});

it('un bloque de varias líneas no pasa', function () {
    expect(revisarComentarios("<?php\n\n/*\n * Primero.\n * Luego.\n */\n\$a = 1;\n"))->toBe(['ocupa 4 líneas']);
});

it('varios // seguidos son un párrafo, también con #', function () {
    expect(revisarComentarios("<?php\n\n// Uno.\n// Dos.\n\$a = 1;\n# Tres.\n# Cuatro.\n"))
        ->toBe(['2 líneas // seguidas', '2 líneas // seguidas']);
});

it('más de 100 caracteres no pasa', function () {
    expect(revisarComentarios("<?php\n\n\$a = 1; // ".str_repeat('x', 100)."\n"))->toBe(['tiene 103 caracteres']);
});

it('un docblock solo de etiquetas para PHPStan sí puede tener varias líneas', function () {
    $fuente = "<?php\n\n/**\n * @param  array<int, true>  \$a\n * @return list<string>\n */\nfunction f(array \$a): array\n{\n    return [];\n}\n";

    expect(revisarComentarios($fuente))->toBe([]);
});

it('un docblock con prosa no pasa aunque tenga etiquetas', function () {
    expect(revisarComentarios("<?php\n\n/**\n * Suma.\n *\n * @return int\n */\nfunction g(): int\n{\n    return 1;\n}\n"))
        ->toBe(['ocupa 5 líneas']);
});

it('solo cuentan las líneas agregadas, y un // bajo uno viejo arma párrafo', function () {
    $fuente = "<?php\n\n/*\n * Viejo.\n */\n\$a = 1;\n// Viejo.\n// Nuevo.\n\$b = 2;\n";

    expect(revisarComentarios($fuente, [6 => true]))->toBe([])
        ->and(revisarComentarios($fuente, [8 => true]))->toBe(['2 líneas // seguidas']);
});

it('un // al final de una línea de código no cuenta como párrafo', function () {
    expect(revisarComentarios("<?php\n\n\$a = 1; // Por esto.\n\$b = 2; // Y por esto.\n"))->toBe([]);
});

it('lee los rangos del diff unificado', function () {
    $diff = implode("\n", [
        'diff --git a/app/A.php b/app/A.php',
        '--- a/app/A.php',
        '+++ b/app/A.php',
        '@@ -3,0 +4,2 @@',
        '+x',
        '+y',
        '@@ -10 +12 @@',
        '-z',
        '+w',
    ]);

    expect(array_keys(lineasAgregadas($diff)['app/A.php']))->toBe([4, 5, 12]);
});
