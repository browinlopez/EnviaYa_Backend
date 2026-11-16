# 47 — Ruta de madurez del backend Laravel 12

> Este es el índice para convertir recomendaciones en entregables verificables. **No se instala todo automáticamente.**

## Etapa 1 — Baseline de ingeniería (obligatoria)

- Arquitectura modular y archivo pequeño: `00`–`05`, `14`, `17`.
- API, seguridad, validación, recursos, errores: `04`, `08`, `09`, `32`–`35`.
- ORM/queries, índices y benchmark básico: `21`, `22`, `24`, `25`, `28`, `29`.
- Tests y CI mínimo: `11`, `12`, `13`, `41`, `42`, `43`.

**Definition of Done:** hay endpoint documentado, autorización por registro, prueba positiva/negativa, consulta acotada, formato uniforme, PR pequeño y pipeline en verde.

## Etapa 2 — Operación confiable (para API productiva)

- Migraciones compatibles y backups probados: `39`.
- Observabilidad, alertas, trazabilidad, runbooks: `44`.
- Dependencias y seguridad de supply-chain: `46`.
- Integraciones, timeouts, webhooks e idempotencia: `36`, `37` **si existen**.
- Jobs, Horizon y scheduler: `38` **si hay tareas asíncronas**.

**Definition of Done:** fallos diagnosticables, jobs reproducibles, riesgos de cambios de esquema controlados, incidentes con recuperación documentada.

## Etapa 3 — Alto volumen y casos complejos (según medición)

- Reverb con escala/presencia: `23`, `27`, `30`, `31`, `45` si producto necesita tiempo real.
- Importaciones y exportaciones masivas: `40` si maneja archivos.
- Outbox/consistencia entre sistemas: `37` si perder eventos sería crítico.
- Optimización adicional por query fingerprint y carga real: `24`, `25`, `30`.

**Definition of Done:** se demuestra el beneficio con datos reales, y existe métrica, rollback y responsable de operación.

## Matriz de auditoría por funcionalidad

| Pregunta | Evidencia esperada |
|---|---|
| ¿Dónde está la responsabilidad? | Ruta exacta a Controller/Action/Query/Resource/Policy |
| ¿Quién puede acceder? | Middleware + Policy + filtro de ámbito + prueba cross-tenant |
| ¿Puede romper cliente? | Contrato OpenAPI y test de compatibilidad |
| ¿Puede duplicar efectos? | Unique/idempotency/transacción cuando sea necesario |
| ¿La consulta escala? | Paginación, query count, índice y EXPLAIN |
| ¿Qué ocurre si falla proveedor/cola? | Timeout, retry seguro y recuperación documentada |
| ¿Cómo lo detecto? | Logs correlacionados, métricas y alerta accionable |
| ¿Cómo lo despliego? | Migración compatible, rollback de código y datos |

## Regla definitiva

**No se persigue «más capas» ni «menos líneas» como fin en sí mismo.** Se persigue tiempo corto para localizar y cambiar una funcionalidad, menos errores, consultas eficientes, seguridad verificable y operación confiable.
