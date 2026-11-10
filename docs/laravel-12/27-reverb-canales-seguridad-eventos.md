# 27 — Reverb: diseño de canales, autorización, eventos y pruebas

> **Regla:** los canales WebSocket constituyen una superficie de seguridad independiente de las rutas HTTP. Reverb distribuye señales de cambio; la API/DB conserva el estado verdadero.

## 1. Nomenclatura estable y específica

| Patrón de canal | Tipo | Quien escucha |
|---|---|---|
| `tenants.{tenantId}.orders` | Private | Usuarios autorizados a ver el listado de órdenes de ese tenant |
| `orders.{orderId}` | Private | Usuarios autorizados a leer esa orden |
| `users.{userId}.notifications` | Private | El propio usuario / perfiles de servicio autorizados |
| `tenants.{tenantId}.operators` | Presence | Usuarios autorizados a compartir información de presencia |
| `system.health` | Public **solo si no contiene datos sensibles** | Clientes anónimos |

No crear un único `app.updates` que mezcle tenants. No incluir PII ni IDs secretos en nombres de canales. No confiar en que el frontend "oculte" canales: **el servidor debe verificar permisos** al autorizar la suscripción.

## 2. Autorización en una clase pequeña

**`app/Modules/Orders/Broadcasting/OrderChannel.php`** (ejemplo: `tenant_id` pertenece al usuario):

```php
<?php

namespace App\Modules\Orders\Broadcasting;

use App\Models\User;
use App\Modules\Orders\Models\Order;

final class OrderChannel
{
    public function join(User $user, int $orderId): bool
    {
        return Order::query()
            ->forTenant($user->tenant_id)
            ->whereKey($orderId)
            ->where('user_id', $user->id)
            ->exists();
    }
}
```

**`routes/channels.php`** (registrar el canal sin lógica de negocio):

```php
use App\Modules\Orders\Broadcasting\OrderChannel;
use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('orders.{orderId}', OrderChannel::class);
```

El ejemplo supone el campo `orders.user_id`; **ajustar al modelo de roles real** (cliente, comercial, operador, admin) y preferir delegación a una Policy/consulta de autorización reutilizable sin duplicar lógica. Evitar consulta sin `tenant_id` aunque el ID parezca impredecible. Asegurar que la ruta de autorización de broadcasting use los guards/middleware realmente configurados (`web`, Sanctum u otro); `/broadcasting/auth` debe autenticar y devolver 403 a usuarios sin acceso.

**Eficiencia:** la función `join` se ejecuta por suscripción; debe usar `exists()` y filtros indexables, nunca hidratar colecciones, ejecutar reportes o hacer llamadas externas por cada cliente.

## 3. Evento tipado, payload mínimo, after commit

**`app/Modules/Orders/Events/OrderStatusChanged.php`**:

```php
<?php

namespace App\Modules\Orders\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

final class OrderStatusChanged implements ShouldBroadcast, ShouldDispatchAfterCommit
{
    public function __construct(
        public readonly int $orderId,
        public readonly string $status,
    ) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel('orders.'.$this->orderId)];
    }

    public function broadcastAs(): string
    {
        return 'orders.status-changed.v1';
    }

    public function broadcastWith(): array
    {
        return ['order_id' => $this->orderId, 'status' => $this->status, 'version' => 1];
    }
}
```

El evento se despacha desde el `Action` después de persistir el estado; `ShouldDispatchAfterCommit` asegura que no se envíe antes de completar las transacciones abiertas en la conexión pertinente. En aplicaciones con varias DB, servicios externos o necesidad de entrega confiable ante caída del proceso entre commit y dispatch, evaluar **transactional outbox**; after-commit no es garantía de entrega exactamente una vez.

No emitir un modelo entero como propiedad pública, pues el payload por defecto puede incluir campos no deseados. Usar `broadcastWith()`, contrato versionado e IDs o snapshots mínimos. Evitar `ShouldBroadcastNow` como valor predeterminado de endpoints sensibles a latencia; usar cola y supervisar workers.

## 4. Conexiones, servidores y escalado

- Configurar `allowed_origins` en Reverb; no usar `*` indiscriminadamente.
- Proteger cookies / Bearer / tokens según frontend; no exponer `REVERB_APP_SECRET`.
- `reverb:start` es proceso persistente: gestor de procesos y reinicio en despliegues (`reverb:restart`).
- Workers `queue:work` con supervisión; medir espera, reintentos y fallos. La cola de broadcast puede separarse de trabajos muy pesados si el proyecto lo necesita.
- Para multiinstancia, Reverb escala a través de Redis y balanceador; validar infraestructura antes de activar `REVERB_SCALING_ENABLED`.
- Evitar hacer una consulta de base de datos *por cada cliente* cuando se emite un evento; calcular una vez el contexto mínimo y publicar al canal correcto.
- En reconexión: cliente vuelve a consultar datos por HTTP (o hace sincronización incremental por versión); un evento perdido no debe causar estado definitivo incorrecto.

## 5. Pruebas obligatorias

1. Usuario del tenant A se suscribe a su orden: autorizado.
2. Usuario del tenant B intenta suscribirse al mismo canal: **denegado**.
3. Orden existe pero no pertenece al usuario autorizado: denegado.
4. Un rollback no emite el evento como cambio confirmado.
5. Evento contiene solo `order_id`, `status` y `version` (no PII).
6. Evento se despacha desde caso de uso correcto, después de commit, y el cliente recupera estado por HTTP.
7. Reconexiones y múltiples suscripciones no generan duplicados incontrolados (idempotencia en consumidor).
8. En carga: medir latencia de autorización de canales, cola y mensajes por segundo, no solo conexiones WebSocket.

**Fuentes:** https://laravel.com/docs/12.x/broadcasting , https://laravel.com/docs/12.x/reverb , https://laravel.com/docs/12.x/queues .
