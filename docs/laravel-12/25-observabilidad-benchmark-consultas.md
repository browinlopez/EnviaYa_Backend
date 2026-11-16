# 25 — Medición real: SQL, N+1, latencia y regresiones

> **Nada se declara optimizado sin evidencia previa y posterior.** La latencia de la API también depende de PHP, Redis, red, serialización, colas y carga del servidor, no únicamente de la base de datos.

## Métricas por endpoint crítico

| Métrica | Qué revela |
|---|---|
| `p50 / p95 / p99` de respuesta HTTP | Experiencia típica y colas de latencia |
| N.º de consultas SQL/request | N+1, trabajo redundante |
| Tiempo acumulado de consultas/request | Presión sobre DB |
| Tiempo de la consulta más lenta | Scan, sorting, contención, locks |
| Filas leídas/retornadas, heap y tamaño de payload | Sobrecarga de hidratación / transmisión |
| Tasa de errores, saturación del pool DB, bloqueos | Incidentes, capacidad y estabilidad |
| `queue_wait_ms`, `queue_runtime_ms`, reintentos | Retrasos de eventos Reverb y jobs |

Define objetivos de SLO/SLI según tráfico real; no imponer un supuesto universal de 50 ms. Para endpoints críticos, evaluar escenarios p95 bajo concurrencia y dataset realista. Reportar tamaño del conjunto, motor, hardware, caché fría/caliente, número de usuarios simulados y versión del código.

## Diagnóstico sin filtrar secretos

El proveedor puede instrumentar `DB::whenQueryingForLongerThan` para recibir aviso cuando el **tiempo SQL acumulado de una petición** supera un umbral. No confundir esto con detectar automáticamente **una única consulta lenta**.

```php
use Illuminate\Database\Connection;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;

DB::whenQueryingForLongerThan(400, function (Connection $connection, QueryExecuted $event): void {
    logger()->warning('Database query budget exceeded', [
        'connection' => $connection->getName(),
        'last_query_ms' => $event->time,
    ]);
});
```

En desarrollo/staging, `DB::listen()` permite obtener eventos `QueryExecuted`; registrar **nombre de endpoint, SQL normalizada, duración y recuentos**, con enmascaramiento de bindings sensibles. No activar el log de todas las consultas sin muestreo ni enviar credenciales/PII a logs. Para localizar N+1 usar Laravel Telescope o herramientas equivalentes en entornos controlados cuando estén disponibles.

Detectar lazy loading de forma explícita, tras revisar compatibilidad con el código heredado:

```php
use Illuminate\Database\Eloquent\Model;

Model::preventLazyLoading(! app()->isProduction());
```

El control es una **decisión del proyecto**, no una garantía automática de no-N+1. Mantener tests que detecten un incremento anormal del número de consultas cuando se aumenta el número de registros retornados.

## Protocolo reproducible de mejora

1. Capturar baseline: query count, latencias por percentil, uso de memoria y `EXPLAIN`.
2. Localizar si el problema es N+1, fetch excesivo, falta de índice, `OFFSET`, orden, joins, bloqueo, cómputo PHP o saturación.
3. Cambiar **una** causa a la vez, en un módulo, con test funcional.
4. Repetir bajo misma carga/datos y comparar los percentiles.
5. Documentar el impacto en lecturas **y escrituras** (los índices y la caché tienen costos).
6. Incluir plan de reversión si hay migración, caché o topología nueva.

## Plantilla de evidencia para un PR

```md
### Rendimiento
- Endpoint + escenario:
- Motor/versión y tamaño del dataset:
- Antes: p50 / p95, queries/request, SQL ms, memoria:
- Después: p50 / p95, queries/request, SQL ms, memoria:
- EXPLAIN (resumen de plan y filas):
- Índices creados/cambiados y costo estimado en writes:
- Prueba de permisos por tenant y regresión:
- Riesgos, despliegue, rollback:
```

**Fuentes:** https://laravel.com/docs/12.x/database , https://laravel.com/docs/12.x/eloquent-relationships , https://laravel.com/docs/12.x/telescope .
