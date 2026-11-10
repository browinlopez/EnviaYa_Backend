# 48 — Normas ejecutables: de documentación a controles de CI

## Criterio

Las normas viven primero en una guía, después en una comprobación automatizada y finalmente en una política de revisión. El pipeline **no refactoriza ni escribe** sobre la rama del contribuidor: falla con un error accionable.

## Mínimo obligatorio por pull request

1. `composer validate --no-check-publish` y `composer audit` con el lockfile válido.
2. `./vendor/bin/pint --test` sin cambios automáticos.
3. `./vendor/bin/phpstan analyse --no-progress` con Larastan, nivel inicialmente 5 y subida progresiva.
4. `php artisan test` con la base de datos correcta para el proyecto; no suponer equivalencia SQLite/PostgreSQL.
5. Revisión de archivos enormes y límites de dependencias como avisos iniciales, no bloqueos.
6. Pruebas específicas por contratos, autorización cross-tenant y cambios de SQL con efectos persistentes.

## Adopción sin interrupciones

- Instalar dependencias verificando compatibilidad real con `composer.lock`.
- Aplicar plantillas de `tooling/` manualmente tras revisar el repositorio y cambiar la versión de PHP.
- Activar checks obligatorios en protección de rama **solo después** de que funcionen.
- Para legacy, usar una baseline de PHPStan versionada y reducirla gradualmente.
- Sin habilitar formatter/autofix que escriba commits en CI.

## Comandos

```bash
composer validate --no-check-publish
composer audit
./vendor/bin/pint --test
./vendor/bin/phpstan analyse --no-progress
php artisan test
python3 scripts/check_file_sizes.py --root .
python3 scripts/check_module_boundaries.py --root .
```

Si una herramienta aún no está instalada, la adopción se detalla en `49`–`53`; no se debe suprimir su fallo silenciosamente. El workflow provisto en `tooling/github/` sirve de **punto de partida**, no reemplaza la integración con Redis/Reverb ni bases de datos productivas.
