# 54 — Operación y recuperación

## Requisitos previos a producción

- APP_DEBUG desactivado, APP_KEY estable y con procedimiento seguro de rotación.
- Claves, credenciales DB, tokens de terceros y secretos Reverb fuera del repositorio.
- Variables validadas por entorno; permisos mínimos de DB y de filesystem.
- Logs estructurados sin PII ni secretos; correlación por request y job.
- Endpoint health/readiness que **no** exponga detalles privados (según despliegue).
- Jobs y Reverb supervisados; restart coordinado en cada release, como documenta Laravel.
- Redis monitorizado y límites de recursos; política de retención de cache.
- Backups de DB y object storage cuando aplique, cifrados y con **restauraciones ensayadas**.
- Estrategia expand/migrate/contract y rollback de aplicación que no destruya datos.

## Runbook mínimo

1. Detectar el síntoma y evaluar blast radius.
2. Ubicar alertas, request IDs, versión desplegada, errores DB y estado de colas.
3. Mitigar (desactivar feature, detener consumos, rollback compatible o restaurar según plan).
4. Registrar línea de tiempo, diagnóstico, recuperación, responsable y acciones preventivas.

## Tiempos de recuperación

RPO/RTO dependen del producto; son objetivos de negocio, no valores garantizados por Laravel.
