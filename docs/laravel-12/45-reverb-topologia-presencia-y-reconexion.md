# 45 — Reverb avanzado: topología, presencia y reconexión

**Estado:** evolutivo; solo aplicar escala y presencia donde el producto lo exija.

## Separación de responsabilidades

- `Event` transmite **hecho de negocio** y datos mínimos (`orderId`, `status`, `updatedAt`, `version`).
- `Channel` autoriza la suscripción de cada usuario; valida tenant/registro en servidor.
- `Job` orquesta envío asíncrono si se justifica; no bloquear el request HTTP por un fan-out grande.
- API HTTP provee estado canónico de recursos; WebSocket es señal de actualización, **no fuente única de verdad**.

## Localización modular

```text
app/Modules/Orders/Events/OrderStatusChanged.php
app/Modules/Orders/Broadcasting/OrderChannel.php  # si amerita callback/clase separada
app/Modules/Orders/Queries/CanSubscribeOrder.php   # solo si autorización compleja
routes/channels.php                                # registro explícito
```

## Esquema de eventos

- Nombre semántico y estable: `order.status.changed`.
- Incluir versión del payload y referencia a entidad; nunca secretos/PII extensa.
- `ShouldDispatchAfterCommit` para cambios que deben existir en BD antes de emitir.
- Agrupar eventos de un cambio de negocio para evitar tormentas de mensajes.
- Eventos duplicados o fuera de orden: incluir versión o timestamp fiable, consultar HTTP para reconciliar.

## Canales

- `private` para datos restringidos; `presence` solo si **se necesita saber quién está conectado** y ese dato puede compartirse.
- No usar canal público por comodidad. Autorización canal ≠ autorización sobre cada endpoint.
- Convención de nombre lógico: `tenant.{tenantId}.order.{orderId}` mediante `new PrivateChannel(...)`; Laravel aplicará el prefijo de transporte `private-` correspondiente. El callback debe validar usuario y objeto: el nombre no es garantía de seguridad.
- Evitar un canal por microevento si puede reutilizarse un canal de recurso de forma segura.

## Operación

- Proxy WSS/HTTPS, `allowed_origins`, secretos, supervisión, límites de conexiones/descriptores y Redis para escalado cuando corresponda.
- Prueba de caída/reinicio: cliente se reconecta y actualiza su estado mediante HTTP.
- Medir conexiones, memoria, mensajes, fallos de autorización, tiempo de cola y reconexiones.

**Fuentes:** https://laravel.com/docs/12.x/reverb · https://laravel.com/docs/12.x/broadcasting
