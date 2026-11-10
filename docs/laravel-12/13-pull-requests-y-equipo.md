# 13 — Colaboración, Git y Pull Requests

## Branches

`feature/orders-create`, `fix/orders-authorization`, `refactor/orders-queries`, `chore/pint-config`. Preferir ramas pequeñas, vida corta y una intención coherente.

## Commits

Usar **Conventional Commits** cuando el equipo lo adopte:

- `feat(orders): add creation endpoint`
- `fix(orders): restrict reads to owner`
- `test(orders): cover invalid quantity`
- `refactor(orders): extract listing query`

## Tamaño del PR

No imponer un número universal de archivos/líneas, pero **preferir PRs revisables**: cambio funcional puntual, sin refactor ajeno. Si debe existir una migración grande, dividir en etapas compatibles.

## PR debe explicar

- Problema y resultado observable.
- Módulo y rutas/contratos afectados.
- Lista breve de archivos y por qué se tocaron.
- Compatibilidad, migraciones, riesgos y rollback.
- Tests realmente ejecutados, resultados y excepciones.

## Code review

Revisar seguridad y scope por tenant, compatibilidad de API, transacciones, consultas N+1, pruebas y tamaño/cohesión. Evaluar si la nueva abstracción reduce complejidad **antes** de pedir más carpetas.

## Concurrencia de equipo

- Asignar ownership por módulo, no «un desarrollador edita todos los archivos compartidos».
- Evitar modificar providers/rutas centrales si el cambio puede vivir dentro de su módulo; documentar cuándo deben editarse.
- Proponer ADR corto para decisiones que afectan varios módulos.
- Mantener un solo lenguaje de naming por proyecto (clases en inglés u otro estándar acordado).

## CI recomendada

Composer install con lockfile → Pint `--test` → suite de pruebas → análisis estático si configurado → despliegue con aprobación del pipeline. No aprobar un PR rojo por defecto.
