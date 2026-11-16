# 43 — Pruebas de contrato, DB, concurrencia y WebSocket

**Estado:** añadir progresivamente sobre la base de Feature Tests del documento 11.

## Pirámide por riesgo

| Tipo | Prioridad |
|---|---|
| Unit tests de reglas puras | Rápidos, claros, sin DB si no la requieren |
| Feature tests HTTP | Principal fuente de verdad del API, auth, errors, resources |
| DB real MySQL/PostgreSQL en CI | Queries, índices, constraints, locking y SQL del motor usado |
| Contract tests | Compatibilidad OpenAPI y consumidor |
| Integración controlada | Proveedor externo sandbox/colas/storage/Reverb |
| Carga y concurrencia | Rutas críticas con volumen y condiciones de carrera |

SQLite puede ayudar en pruebas rápidas, pero no demuestra rendimiento ni equivalencia de locks/SQL con PostgreSQL o MySQL.

## Casos que más errores capturan

1. Registros cruzados de tenant: lectura, escritura, exportación, búsqueda y websockets.
2. N+1: test con 1, 10 y 100 elementos; contrastar crecimiento de query count.
3. Paginación: tamaño máximo, orden estable, cursor y concurrent inserts.
4. Idempotencia: mismo request enviado varias veces simultáneamente.
5. DB transaction: rollback, deadlock y constraints.
6. HTTP provider: timeout, 429, firma de webhook inválida y evento fuera de orden.
7. Jobs: doble ejecución, retries, `afterCommit` y fallos permanentes.
8. Reverb: acceso al canal, payload mínimo, reintento/conexión caída y reconciliación por HTTP.

## Escenarios de pruebas

- Usar factories/faker y fixtures versionadas; no copiar datos productivos.
- `Event::fake()` comprueba dispatch; **no** prueba entrega del WebSocket. `Queue::fake()` no verifica la ejecución real del job.
- Si el test necesita probar interacción entre varios listeners, evitar un fake que oculte ese comportamiento.
- Pruebas de carga fuera de la suite rápida, con datos representativos y límites de seguridad.
- Métricas de aceptación: p95 de rutas importantes, errores, locks, backlog y memoria; definir números según hardware/volumen real, no universales.

**Fuentes:** https://laravel.com/docs/12.x/testing · https://laravel.com/docs/12.x/http-tests · https://laravel.com/docs/12.x/queues · https://laravel.com/docs/12.x/broadcasting
