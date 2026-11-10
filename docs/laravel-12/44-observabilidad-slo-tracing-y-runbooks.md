# 44 — Observabilidad, SLO, trazas y recuperación operativa

**Estado:** alta prioridad para producción.

## Qué medir

- HTTP: RPS, errores 4xx/5xx, p50/p95/p99, payload/endpoint y saturación.
- DB: consultas lentas agrupadas por fingerprint, locks, CPU, conexiones y duración acumulada.
- Colas: backlog, espera, fallos y reintentos.
- Redis: latencia, aciertos/fallos de cache, memoria y expiraciones.
- Reverb: conexiones, autenticaciones fallidas, desconexiones, fan-out y latencia de eventos.
- Dependencias: latencia, errores y cuota consumida por proveedor.

## Correlación y privacidad

- Adjuntar `request_id` y, donde corresponda, `correlation_id` a logs y jobs para seguir una operación completa.
- Campos seguros: módulo, operación, estado y referencias opacas. No registrar tokens, contraseñas ni cuerpos privados.
- Evitar etiquetas métricas con alta cardinalidad (`user_id`, `order_id` como labels de series). Dejar esos IDs para trazas/logs protegidos cuando corresponda.
- Retención y acceso de logs según sensibilidad y obligaciones legales del sistema.

## Laravel nativo

- **Pulse**: vistas de consultas lentas, requests, uso de la aplicación y más.
- **Horizon**: supervisión operacional si se usan colas Redis.
- **Telescope**: depuración, con acceso restringido y política de datos/producción.
- `DB::whenQueryingForLongerThan()` y `DB::listen()` según el objetivo; no registrar SQL/bindings sensibles sin control.
- Salud: endpoint básico de readiness/liveness sin exponer versiones, secretos o infraestructura interna.

## SLO y runbooks

Cada operación crítica define disponibilidad y latencia objetivo basados en mediciones; alertas se configuran por impacto, no por ruido. Crear runbooks para DB caída, saturación de pool, cola detenida, WebSocket fuera de servicio, credenciales filtradas y migración fallida.

**Fuentes:** https://laravel.com/docs/12.x/pulse · https://laravel.com/docs/12.x/horizon · https://laravel.com/docs/12.x/telescope · https://laravel.com/docs/12.x/database
