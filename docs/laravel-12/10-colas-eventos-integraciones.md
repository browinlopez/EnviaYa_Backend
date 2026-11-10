# 10 — Jobs, eventos e integraciones

## Job

Una clase, una tarea asíncrona. Ejemplos: `SendOrderReceipt`, `SyncStockToErp`. Definir `tries`, `timeout`, backoff y estrategia de fallos según costo/criticidad; no fijar parámetros arbitrarios para todos.

- El Job recibe IDs / datos serializables, resuelve el estado actual y valida que el efecto sigue siendo pertinente.
- Diseñar ejecución idempotente, ya que la cola puede reintentar.
- No mantener transacciones abiertas mientras se hace HTTP externo.
- Despachar `afterCommit()` cuando necesita datos que deben estar confirmados.

## Event / Listener

- Evento nombrado por hecho ocurrido (`OrderCreated`), no por acción futura (`SendEmail`).
- Listeners pequeños, con responsabilidad única; no esconder el camino crítico de negocio en una red de eventos opacos.
- Para side effects esenciales, documentar fallos y reintentos.

## Integraciones

- Encapsular cada proveedor en `Integrations/<Proveedor>/...` o en un módulo técnico, según propiedad funcional.
- Usar HTTP Client Laravel con timeout, retry controlado y tratamiento de errores (con cuidado en operaciones no idempotentes).
- Credenciales mediante `config/services.php` o config propia; nunca `env()` en lógica de negocio.
- Fakes (`Http::fake`, `Queue::fake`, `Event::fake`) en pruebas donde corresponda y verificaciones de integración reales en ambientes controlados.
- Trazabilidad con IDs de solicitud, nunca datos secretos.

## Cuándo hacerlo síncrono

Si la respuesta necesita el resultado para ser correcta (por ejemplo validar saldo autorizado), no delegar ciegamente a un Job sin diseñar estado intermedio y contrato eventual.
