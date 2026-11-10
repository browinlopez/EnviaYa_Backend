# 38 — Colas, Horizon, scheduler y capacidad operativa

**Estado:** alta prioridad si la API envía correos, procesa imágenes, consume proveedores o emite eventos.

## Decisión síncrono vs asíncrono

- Síncrono: la respuesta HTTP no puede ser correcta sin el resultado (autorización de saldo, check de disponibilidad).
- Job: trabajo lento o diferible (correo, exportación, thumbnails, notificación, integración no crítica).
- Nunca mover automáticamente una validación transaccional crítica a un Job sin un estado pendiente bien diseñado.

## Organización modular

```text
app/Modules/Reports/Jobs/GenerateReport.php
app/Modules/Orders/Jobs/NotifyOrderCreated.php
app/Modules/Orders/Actions/CreateOrder.php
routes/console.php
config/horizon.php           # solo si se adopta Horizon
```

## Reglas de Job

1. Serializar IDs/valores imprescindibles, no estado de request ni servicios no serializables.
2. Declarar timeout, número de intentos/backoff y política para fallas permanentes.
3. Implementar idempotencia: un Job puede ejecutarse más de una vez.
4. Usar `afterCommit` para datos que se crean dentro de transacciones.
5. `WithoutOverlapping` / `ShouldBeUnique` si hay riesgo específico; evaluar almacenamiento compartido para locks.
6. Separar colas por criticidad y coste: por ejemplo `critical`, `default`, `exports`, `broadcasts`, siempre que la operación lo justifique.
7. Supervisar workers y reiniciarlos en deploy; no depender de `queue:work` manual en terminal.
8. Configurar `retry_after` y `timeout` coherentemente para evitar ejecuciones simultáneas inesperadas.

## Horizon y scheduler

- Horizon aporta métricas y gestión a colas **Redis**; no asumirlo para drivers distintos.
- Scheduler en más de un nodo: `onOneServer()` y `withoutOverlapping()` donde corresponda, usando cache compartida apropiada.
- Alertas: mensajes fallidos, tiempo de espera en cola, backlog y tasa de reintentos; proteger dashboards.

## Tests y operaciones

Comprobar dispatch, idempotencia y tratamiento de duplicados, fallos de dependencias y tareas solapadas. Documentar cómo reprocesar un job fallido sin duplicar efectos.

**Fuentes:** https://laravel.com/docs/12.x/queues · https://laravel.com/docs/12.x/horizon · https://laravel.com/docs/12.x/scheduling
