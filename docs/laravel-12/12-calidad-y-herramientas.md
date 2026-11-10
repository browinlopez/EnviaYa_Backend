# 12 — Estilo, tipado y validación automatizada

## Estilo

- Laravel Pint es la primera autoridad de formato del repositorio; mantener su preset/configuración actual.
- Métodos y parámetros con tipos cuando aporten claridad (`void`, `Order`, `LengthAwarePaginator`); evitar `mixed` salvo necesidad.
- Nombres descriptivos; clases verbales para casos de uso (`CreateOrder`), sustantivos para entidades (`Order`).
- `declare(strict_types=1)` puede adoptarse gradualmente por decisión del equipo; no reescribir todos los archivos solo para agregarlo.
- Comentarios explican el **porqué**, no repiten el código. Docblocks cuando agregan información útil para análisis estático.

## Verificaciones

```bash
./vendor/bin/pint --test
php artisan test
php artisan route:list --path=api
```

Opcionales **si están instalados y configurados**: Larastan/PHPStan, Rector en migraciones planificadas, análisis de dependencias. No asumir que existen ni agregar herramientas a espaldas del equipo.

## PR limpio

- Sin imports sin usar, `dd`, código comentado, TODO indefinidos ni `catch` vacíos.
- No incluir cambios de estilo en archivos ajenos al objetivo del PR.
- Evitar varios formatos de error o patrones de controllers entre módulos.
- `composer.lock` cambia solo cuando se agregan/actualizan dependencias justificadas.

## Métrica útil

Más valioso que «promedio de líneas»: número de archivos tocados por cambio, tasa de fallos, cobertura de rutas críticas, tiempos de revisión y frecuencia de regresiones.
