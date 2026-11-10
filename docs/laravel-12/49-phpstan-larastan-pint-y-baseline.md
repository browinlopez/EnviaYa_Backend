# 49 — Pint + Larastan/PHPStan

## Instalación verificable

```bash
composer require --dev larastan/larastan:^3.0
# Laravel Pint suele venir con el esqueleto; si falta:
composer require --dev laravel/pint
```

Verificar con `composer show larastan/larastan laravel/pint` (o individualmente). Copiar `tooling/quality/phpstan.neon.dist` a la raíz como `phpstan.neon.dist` y `tooling/quality/pint.json` como `pint.json` **solo si** el repositorio no tiene reglas equivalentes. No reemplazar configuraciones previas sin diff y aprobación.

```bash
./vendor/bin/pint --test
./vendor/bin/phpstan analyse --no-progress
```

## Reglas recomendadas

- Iniciar en nivel 5, aumentar por módulo hasta 7–8, sin `ignoreErrors` genérico.
- Tipar argumentos, resultados y estructuras de Collection en PHPDoc cuando el analizador lo requiera.
- No usar `mixed`/`@phpstan-ignore-next-line` para ocultar diseños incompletos.
- Legacy: `./vendor/bin/phpstan analyse --generate-baseline` una vez; documentar deuda y evitar que aumente.
- Pint es estilo, **no** verifica integridad de autorización, calidad de queries ni arquitectura.

Referencia: https://github.com/larastan/larastan ; https://laravel.com/docs/12.x/pint
