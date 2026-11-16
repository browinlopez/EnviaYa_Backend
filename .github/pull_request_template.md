## Objetivo del cambio

<!-- ¿Qué problema resuelve y cuál es el resultado observable? -->

## Módulo y archivos modificados

<!-- Listar rutas y por qué cada una cambió -->

## Contrato / seguridad / datos

- [ ] No cambia contrato API; si cambia, se documentó.
- [ ] Revisé autenticación, autorización y ámbitos por usuario/tenant.
- [ ] No hay migración; si la hay, documenté backfill y rollback.
- [ ] No hubo refactors fuera de alcance.

## Pruebas ejecutadas (comandos y resultados)

- [ ] Feature tests de este módulo.
- [ ] Tests de permisos y validación cuando corresponde.
- [ ] Pint `--test`.

## Riesgos y despliegue

<!-- Variables, jobs, índices, migración y rollback si aplican -->

### Consultas / tiempo real (cuando aplique)
- [ ] Revisé `docs/laravel-12/29-checklist-pr-rendimiento.md`
- [ ] Consultas DB: scopes/filtros, índice y `EXPLAIN` cuando corresponde
- [ ] Benchmark antes/después con dataset y escenarios comparables
- [ ] Reverb: canal privado, autenticación, after-commit, payload mínimo y tests

### Calidad, integraciones y compatibilidad (cuando aplique)
- [ ] Revisé contrato OpenAPI, clientes actuales y formato de errores (`34`).
- [ ] Revisé seguridad por registro/tenant, mass assignment y OWASP (`32`–`35`).
- [ ] Webhook/API externa: firma, timeout, reintentos, deduplicación (`36`–`37`).
- [ ] Cola: after-commit, reintento seguro, fallos/monitorización (`38`).
- [ ] Migración: compatibilidad, backfill y rollback (`39`).
- [ ] CI: Pint, análisis estático si existe, test y dependencias (`41`–`43`).
- [ ] Métricas y plan operativo/rollback si hay riesgo de producción (`44`).
