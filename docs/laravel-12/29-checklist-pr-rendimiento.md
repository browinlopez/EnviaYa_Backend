# 29 — Checklist de rendimiento para PR y revisión de código

Marcar lo que **aplique**; para cambios sin consultas, dejar `N/A` con razón. El objetivo es mejorar, no fabricar trabajo.

## Diseño y ubicación

- [ ] Identifiqué el módulo dueño y modifiqué la menor cantidad de archivos.
- [ ] La relación o scope simple está en `Models/`; la consulta específica está en `Queries/`.
- [ ] El controller, recurso JSON y clase de broadcasting **no ejecutan lecturas ad hoc**.
- [ ] No añadí `Repository`, servicio o helper que simplemente reenvía a Eloquent.
- [ ] El número de líneas por archivo respeta la guía o la excepción está justificada.

## Seguridad y eficiencia SQL

- [ ] Filtro de tenant, usuario y permisos dentro de la consulta SQL cuando aplica.
- [ ] Evité N+1 con eager loading, contadores y `whenLoaded`.
- [ ] Solo traigo filas/columnas requeridas; conservo claves necesarias para relaciones.
- [ ] Uso `exists`/`count`/`sum` en DB cuando corresponda, no colecciones enormes en PHP.
- [ ] Paginación y `per_page` acotados; orden estable y whitelist de `sort`.
- [ ] No introduje SQL crudo con valores interpolados ni columnas provenientes del cliente.
- [ ] Revisé índices reales y `EXPLAIN` cuando hay filtros/joins nuevos en consultas críticas.
- [ ] Si añadí índice, medí impacto en escrituras, espacio y migración en producción.

## Operación y consistencia

- [ ] Transacciones cortas; bloqueos solo donde hay carreras verificables.
- [ ] Redis/cache tiene clave segura con tenant, TTL e invalidación; no afecta autorización.
- [ ] Si hay eventos WebSocket, canal privado y autorización correctos; evento post-commit y payload mínimo.
- [ ] Hay tests para acceso cross-tenant, filtros, paginación, N+1 o emisiones según riesgo.
- [ ] Registré baseline y resultado comparables (queries, p95, memoria y dataset) si el PR optimiza rendimiento.
- [ ] No afirmo una mejora de velocidad sin una medición reproducible.

## Política de aceptación de optimizaciones

Se **acepta** un cambio si conserva comportamiento y contratos, no crea exposición de datos, demuestra mejora bajo escenario comparable y documenta sus costos. **No se acepta** una mejora de benchmark sintético que degrade latencia p95, seguridad o mantenibilidad de forma significativa sin una decisión consciente del equipo.

**Plantilla breve para el PR:** `docs/laravel-12/25-observabilidad-benchmark-consultas.md`.
