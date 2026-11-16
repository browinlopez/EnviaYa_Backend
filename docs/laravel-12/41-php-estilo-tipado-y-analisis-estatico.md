# 41 — Estilo PHP, tipado y análisis estático

**Estado:** obligatorio como base de calidad; activación gradual del nivel de exigencia.

## Convenciones

- `Pint` es la fuente de formato del repositorio, con preset acordado.
- Seguir PSR-12/PSR-4 donde correspondan; usar namespaces y autoload consistentes.
- Tipar parámetros, retornos y propiedades siempre que el contrato sea claro.
- `declare(strict_types=1)` por decisión de equipo; adoptar sin cambios masivos de formato.
- Preferir enums para estados cerrados con semántica de dominio; no para códigos de datos arbitrarios que cambian a diario.
- En Eloquent, añadir anotaciones/generics soportados por el analizador para relaciones, colecciones y paginadores cuando mejore precisión.
- Evitar `mixed`, `array` sin shape/contrato y `object` genérico en fronteras importantes; no crear DTO por trivialidad.
- Nunca ocultar errores con `@`, `try/catch` vacío o `ignoreErrors` global.

## Cuándo extraer una clase

Se justifica cuando hay una responsabilidad estable, test independiente, reusable con comportamiento claro o aislamiento de dependencia externa. **No** solo por exceder cierta cantidad de líneas.

## Comandos sugeridos

```bash
composer validate --strict
composer audit
./vendor/bin/pint --test
php artisan test
# Solo si existe y está configurado:
./vendor/bin/phpstan analyse
```

## En CI

- `pint --test`, tests y `composer audit` según política del repositorio.
- PHPStan/Larastan a nivel creciente y con baseline temporal cuando se hereda deuda técnica.
- Revisar warnings nuevos de forma estricta; no exigir corregir errores históricos en un PR no relacionado.
- Asegurar `composer.lock` versionado y dependencias de production/dev separadas.

## Ejemplo de responsabilidad

Un controller con inyección de una Action y retorno de Resource puede ser completo en 20–30 líneas; no introducir `BaseController`, `AbstractCrudService` o envoltorios genéricos para reducir aparente repetición.

**Fuentes:** https://www.php-fig.org/psr/psr-12/ · https://laravel.com/docs/12.x/testing · https://laravel.com/docs/12.x/container
