# 15 — Observabilidad, operaciones y despliegue

## Logs

- Log estructurado con contexto útil: `request_id`, `order_id` no sensible, módulo y operación.
- No registrar contraseñas, tokens, datos personales innecesarios o payloads completos de terceros.
- Errores inesperados: dejarlos llegar al manejador global, reportarlos con trazabilidad; los errores esperados del dominio deben mapearse correctamente.

## Salud y colas

- Endpoint de salud configurado en `bootstrap/app.php` si corresponde; no exponer detalles internos.
- Monitorear jobs fallidos, duración, profundidad de cola, consultas lentas y tasa de 4xx/5xx.
- Alertas solo sobre señales operativas accionables.

## Producción

- `APP_ENV=production`, `APP_DEBUG=false`.
- Config y secretos por entorno; usar `config()` en runtime.
- Aprovechar `php artisan optimize` / caches cuando el proceso de despliegue lo prevea, sin mezclar caches viejas con cambios de configuración.
- Migraciones compatibles antes o durante despliegue según la estrategia; backups y plan de rollback.
- Los workers de cola de larga vida deben reiniciarse tras despliegue (`php artisan queue:restart` según infraestructura); en entornos orquestados, validar ciclo de vida de procesos.

## Base de datos y performance

- Índices basados en métricas reales; revisar queries costosas y N+1.
- Validar paginación y límites en endpoints usados por clientes externos.
- Evitar cron/job duplicado entre múltiples instancias: configurar locking cuando el scheduler lo necesite.

## Cambios a infraestructura

Cada cambio con impacto operacional debe traer: dependencia/variable nueva, plan de migración, impacto en jobs, métrica de salud y rollback propuesto.
