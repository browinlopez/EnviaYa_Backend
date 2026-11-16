# 30 — Escala, concurrencia y producción sin degradar lecturas

## Costos que no se resuelven con ORM

Una API puede tener consultas correctas y seguir lenta si la DB está saturada, hay demasiadas conexiones PHP-FPM, las transacciones mantienen locks por segundos o las colas esperan demasiado. La optimización debe analizar el **camino completo**.

## Transacciones cortas

```php
DB::transaction(function () use ($orderId, $tenantId): void {
    $order = Order::query()->forTenant($tenantId)
        ->whereKey($orderId)->lockForUpdate()->firstOrFail();
    $order->update(['status' => 'confirmed']);
}, 3);
```

El ejemplo aplica `forTenant`, pero **también requiere una Policy/autorización** fuera del fragmento antes de modificar. El argumento `3` permite reintentos ante deadlock según Laravel. Mantener la operación dentro de transacción mínima; no hacer llamadas HTTP, emails, archivos ni tareas largas mientras se sostiene un `lockForUpdate()`.

Si el sistema depende de un inventario, saldo o cupo, usar constraints SQL, transacciones y locking/actualizaciones condicionales según el riesgo. Una prueba de concurrencia debe intentar ejecutar dos cambios incompatibles.

## Evitar sorpresas de lecturas replicadas

- Separar conexiones de lectura/escritura solo si el volumen y la operación lo justifican.
- Replicas pueden tener **lag**: una lectura inmediata después de escritura podría devolver datos antiguos. Evaluar `sticky` y/o leer del primario para flujos consistentes.
- Planificar límites de conexiones, timeout, pool y capacidad del servidor SQL; no subir workers ilimitadamente.
- Medir saturación DB, CPU, locks y esperas antes de escalar horizontalmente Laravel.

## Jobs y streaming

- Proceso de exportación o informe largo → `Job` y archivos de descarga, no request HTTP infinito.
- En colas, controlar reintentos, backoff, timeouts, idempotencia y deduplicación.
- Evitar una query SQL por cada elemento de una lista si es posible cargar o actualizar en lotes con seguridad.
- `cursor()`, `lazy()`, `chunkById()` o `lazyById()` se eligen según límites de memoria, modificaciones y semántica; no son intercambiables universalmente.
- Redis ayuda a colas/cache/Reverb pero no es sustituto de índices ni de integridad transaccional.

## Reverb bajo carga

- Controlar número de suscriptores, churn de conexiones, costo de autorización, tasa de mensajes, payload y retraso de cola. Evaluar Laravel Pulse para conexiones y mensajes si el paquete está instalado.
- Revisar descriptores de archivo (`ulimit -n`) y el event loop; en Linux, configuraciones basadas en `stream_select` pueden topar con límites cercanos a 1.024 descriptores, mientras que `ext-uv` puede habilitar mayor capacidad según entorno (validar contra docs y pruebas de carga).
- Preferir 1 mensaje semántico (`order.updated`) a emitir 20 eventos por cambio interno.
- Alinear el número de canales con privacidad y producto, sin crear uno público para simplificar.
- Si Reverb cae o pierde mensajes, el frontend debe reconciliar con la API por HTTP.
- Reiniciar workers (`queue:restart`) y Reverb (`reverb:restart`) según política de despliegue supervisado; validar zero-downtime para tu infraestructura.

## Observabilidad de operaciones

Monitorear continuamente p95 por ruta, consultas lentas por fingerprint, memoria/CPU, errores, conexiones DB, réplicas, redis, workers, canales autorizados y conexiones WebSocket. Alertas deben tener responsable y umbral basado en SLO real.

**Fuentes:** https://laravel.com/docs/12.x/database , https://laravel.com/docs/12.x/queues , https://laravel.com/docs/12.x/reverb , https://laravel.com/docs/12.x/queries .
