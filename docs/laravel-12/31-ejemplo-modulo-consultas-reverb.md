# 31 — Caso completo: API de órdenes, consultas rápidas y Reverb

**Escenario:** backend multi-tenant. GET `api/v1/orders` lista órdenes del tenant autenticado; PATCH actualiza estado; el frontend escucha la orden autorizada en Reverb.

## Mapa de archivos a tocar por cambio

```text
app/Modules/Orders/
├── Models/Order.php                     # relaciones y #[Scope] forTenant
├── Queries/ListOrders.php               # where + with + paginación
├── Actions/UpdateOrderStatus.php        # transacción / cambio estado / dispatch
├── Events/OrderStatusChanged.php        # ShouldBroadcast + after commit
├── Broadcasting/OrderChannel.php        # autorizar suscripción
├── Http/Requests/ListOrdersRequest.php  # validar filtros / per_page
├── Http/Controllers/ListOrdersController.php # delega a Query
├── Http/Resources/OrderResource.php      # whenLoaded
├── Policies/OrderPolicy.php             # autorizar lectura/escritura
└── Routes/api.php                       # endpoints del módulo
routes/channels.php                     # registrar canal del módulo
...
database/migrations/....php            # índice si benchmark lo requiere
tests/Feature/Orders/...               # contrato + seguridad + query count
```

## Ejemplo de Query con filtros controlados

**`Queries/ListOrders.php`:**

```php
<?php

namespace App\Modules\Orders\Queries;

use App\Modules\Orders\Models\Order;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

final class ListOrders
{
    public function handle(int $tenantId, ?string $status, int $perPage): LengthAwarePaginator
    {
        return Order::query()
            ->forTenant($tenantId)
            ->select(['id', 'tenant_id', 'customer_id', 'status', 'created_at'])
            ->when($status !== null, fn ($query) => $query->where('status', $status))
            ->with('customer:id,name')
            ->orderByDesc('id')
            ->paginate(min(max($perPage, 1), 100));
    }
}
```

La consulta no se ejecuta en el modelo, el Resource ni el controlador: toda la lógica de filtrado está concentrada en un `Query Object` identificable. El scope `forTenant` está en el `Model` y no utiliza el valor de tenant recibido del request sin validación del servidor.

## Resource no dispara más SQL

```php
use Illuminate\Http\Resources\Json\JsonResource;

final class OrderResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'status' => $this->status,
            'customer' => new CustomerResource($this->whenLoaded('customer')),
        ];
    }
}
```

El ejemplo presupone una clase `CustomerResource` existente y `customer` cargado por `ListOrders`. Para campos opcionales u otras relaciones, escoger serialización según contrato sin activar lazy loading.

## SQL esperado (conceptual)

```sql
SELECT id, tenant_id, customer_id, status, created_at
FROM orders
WHERE tenant_id = ? AND status = ?
ORDER BY id DESC
LIMIT ? OFFSET ?;
```

Eloquent `paginate()` puede añadir un `COUNT(*)` extra, y `with('customer')` otra consulta `IN (...)` para eager loading; son consultas **por lote**, no una por orden. El SQL exacto depende del driver, filtros y paginación. Medir con `DB::listen` y `EXPLAIN` antes de proponer índices.

## Secuencia de escritura y publicación

1. `FormRequest` valida forma y límites; `Policy` autoriza.
2. `Action` obtiene el pedido **en scope de tenant**, abre transacción breve y actualiza.
3. `OrderStatusChanged` se despacha, con interfaz `ShouldDispatchAfterCommit`.
4. Worker publica en `PrivateChannel('orders.'.$orderId)`.
5. `OrderChannel::join()` comprueba `exists()` de un registro visible, sin relaciones pesadas.
6. El cliente aplica payload versionado y, si necesita el detalle definitivo, vuelve a consultar HTTP.

## Verificación del endpoint

- En un dataset con 10/100/1000 órdenes, la cantidad de SQL para **una página de 30** no debe crecer linealmente con cada orden por N+1.
- Paginación no escapa de tenant ni permite `per_page` excesivo.
- Cambio concurrente preserva integridad; estado solo se publica si transacción se confirma.
- Suscripción del tenant equivocado denegada.
- Benchmark antes/después con registros suficientes e índice apropiado.

## Documentos complementarios

- `21-consultas-en-modelos-y-query-objects.md`: qué va en Model/Scope/Query.
- `24-indices-esquemas-explain.md`: índice y plan.
- `25-observabilidad-benchmark-consultas.md`: mediciones.
- `27-reverb-canales-seguridad-eventos.md`: evento / canal / autorización.

**Fuentes:** https://laravel.com/docs/12.x/eloquent , https://laravel.com/docs/12.x/eloquent-relationships , https://laravel.com/docs/12.x/broadcasting .
