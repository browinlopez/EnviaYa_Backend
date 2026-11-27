# Reglas del repositorio

> **Obligatorias para cualquier persona (o herramienta) que toque este
> repositorio.** El detalle está en [`AGENTS.md`](AGENTS.md) y
> [`docs/laravel-12/`](docs/laravel-12/); lo que no se cumple, y por qué, en
> [`docs/laravel-12/EXCEPCIONES.md`](docs/laravel-12/EXCEPCIONES.md).

## 1. Comentarios: una línea, solo el porqué

> ### MÁXIMO UNA LÍNEA Y ≤ 100 CARACTERES POR COMENTARIO
>
> - Explica **por qué**, nunca **qué** hace el código.
> - **Nada de bloques largos**: ni `/* … */` de varias líneas, ni varios `//` seguidos, ni
>   docblocks con prosa.
> - Permitido: docblocks que solo tienen etiquetas para PHPStan (`@param`, `@return`,
>   `@property`, `@var`…).
> - Lo comprueba `scripts/comentarios.php` en el CI, solo sobre las **líneas nuevas** del PR.

## 2. Flujo de ramas y PR

- [ ] Rama desde `Devs`: `feature/…`, `fix/…` o `chore/…` (minúsculas, guiones, sin tildes).
- [ ] **Un objetivo por PR**, con la plantilla completa (objetivo, riesgos, cómo probar,
      rollback). Si el panel o la app dependen de él, se dice.
- [ ] Se une **solo con squash** y **solo cuando el dueño lo autoriza**.
- [ ] Desplegar = promover `Devs` → `main` (rama de producción).
- [ ] **Nunca** push directo a `Devs` ni a `main`.
- [ ] Commits en español. **Sin atribución de herramientas** (nada de `Co-Authored-By` de IA).

## 3. Antes de pedir el merge

- [ ] `vendor/bin/pint --test`
- [ ] `vendor/bin/phpstan analyse --no-progress --memory-limit=1G`
- [ ] `python scripts/validaciones_en_linea.py --trinquete` (el tope de validaciones en línea
      no sube: la validación nueva va en un `FormRequest`)
- [ ] `php scripts/openapi.php` y luego `php scripts/openapi.php --revisar`
- [ ] `php artisan test` (al menos `tests/Feature/Contrato` y lo del módulo)
- [ ] `php scripts/comentarios.php` (comentarios nuevos de una línea)
- [ ] El CI **Pruebas** en verde (PHP 8.2, 8.3 y MySQL).

## 4. Reglas clave

- [ ] **Sin secretos** en el código, en `.env.example`, en logs ni en respuestas.
- [ ] Autorización por registro y por tenant: el negocio o el conjunto sale de la sesión,
      nunca del cuerpo de la petición.
- [ ] Migraciones **aditivas**, con `down()`; nada que rompa a la app ya publicada.
- [ ] Lo que mueve plata acepta `Idempotency-Key` (middleware `idempotente`).
- [ ] Respuestas y contratos estables: si cambia una ruta o una forma, se regenera OpenAPI y la
      superficie congelada, y se avisa al panel y a la app.
- [ ] Pruebas que no dependen de la hora del día (`Carbon::setTestNow`) ni del orden de las
      claves JSON de MySQL.
