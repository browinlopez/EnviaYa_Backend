# 53 — Verificación de rendimiento con datos reales

## Antes/después para cada endpoint crítico

Registrar: P50/P95 de latencia, cantidad de SQL por request, duración acumulada de SQL, filas leídas/devueltas, memoria, éxito/error, uso de Redis, y EXPLAIN del query dominante.

1. Ejecutar una línea base repetible: mismo hardware, semillas, índices y concurrencia.
2. Detectar query con N+1, filtro en PHP, scans inesperados o falta de índice.
3. Cambiar **una causa medible por vez**; usar Eloquent por defecto y Query Builder/SQL parametrizado donde aporte mejora comprobada.
4. Repetir medición y adjuntar evidencia al pull request.
5. Con volumen representativo, probar locks y degradación bajo concurrencia (no solo latencia sin carga).

## Presupuestos

No definir un presupuesto global ficticio. Cada endpoint de negocio define su SLO y carga esperada, por ejemplo `p95 < 300 ms` **solo si el producto lo exige y las mediciones respaldan su viabilidad**. Validar objetivos con negocio e infraestructura.

## Reverb

Pruebas de autorización por canal, reconexión, cambios durante disconnect, orden observado, backpressure, payloads pequeños, procesos y Redis. El WebSocket avisa de cambios; un fetch HTTP sincroniza el estado final.

## SQL

EXPLAIN (ANALYZE, BUFFERS) en PostgreSQL **cuando se pueda ejecutar con seguridad**; puede ejecutar realmente la consulta. En producción usar cautela y no ejecutar DML de riesgo. Para MySQL, verificar sintaxis/plan según versión.
