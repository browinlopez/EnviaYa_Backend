# 37 — Idempotencia, consistencia y patrón outbox

**Estado:** crítico para pagos, reservas, inventarios, órdenes y trabajos reintentables.

## Regla de idempotencia

Una petición con la misma clave de idempotencia, misma identidad/ámbito y el mismo contenido lógico no debe ejecutar dos veces un efecto irreversible. La misma clave con otro contenido debe responder con conflicto conforme al contrato definido.

## Dónde vive

```text
app/Modules/Orders/
  Actions/CreateOrder.php
  Models/Order.php
  Jobs/DispatchOrderNotification.php
app/Shared/Idempotency/            # solo cuando 2+ módulos realmente lo requieran
  ...
database/migrations/..._create_idempotency_keys_table.php
```

- Identidad de deduplicación: `tenant_id + actor/client + operation + key` (ajustar al modelo de amenazas).
- Restricción única **en base de datos**; un `firstOrCreate()` sin constraint no elimina carreras.
- Decidir cómo guardar respuesta reproducible, hash de parámetros, estado `processing/completed/failed` y expiración.
- La transacción que crea la orden también debe establecer el registro de idempotencia/estado.
- No retener locks de DB mientras se llama a un proveedor externo.

## Outbox transaccional: cuándo sí

Usar una tabla `outbox_messages` creada en la misma transacción que el cambio de negocio cuando **no se puede perder** una notificación/evento si la cola o el proceso cae entre commit y publicación.

- Persistir mensaje de salida junto al estado de negocio.
- Un dispatcher supervisado publica y marca resultados con reintentos; consumidores idempotentes.
- Implementar deduplicación y monitorear mensajes pendientes; outbox no garantiza entrega exactamente una vez.
- No introducir outbox en cada CRUD sin requisito de confiabilidad; para eventos no críticos puede bastar `afterCommit()`.

## Diferencia importante

`DB::transaction`, `ShouldDispatchAfterCommit` y `Job::dispatch()->afterCommit()` evitan disparar antes de confirmar, pero **no aseguran por sí solos** publicación duradera ante toda caída del proceso. Una outbox resuelve otra clase de problema.

## Pruebas

- Dos peticiones concurrentes misma clave → un solo efecto.
- Mismo key distinto cuerpo → error previsible.
- Crash/retry después del commit → no duplicar negocio.
- Outbox: caída del publicador, orden de procesamiento y reproceso manual controlado.

**Fuentes:** https://laravel.com/docs/12.x/database · https://laravel.com/docs/12.x/queues · https://laravel.com/docs/12.x/broadcasting
