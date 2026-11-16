# 21 — Dónde deben vivir las consultas: Models, Scopes y Queries

> **Regla de equipo:** toda consulta nace de **Eloquent o Query Builder de Laravel** y se ubica en la capa dueña de los datos. No mezclar SQL de negocio en controladores, recursos, listeners o rutas.
>
> **Matiz importante:** «las consultas van en los modelos» **no significa** meter cientos de líneas en `Order.php`. Las relaciones y filtros propios del modelo se quedan allí; las consultas complejas o específicas de un caso de uso tienen su propio archivo `Queries/`.

## 1. Reparto exacto de responsabilidades

| Necesidad | Ubicación | Por qué |
|---|---|---|
| Tabla, casts, fillable, relaciones | `Models/Order.php` | Es el contrato persistente del modelo |
| Filtro reutilizable, acotado y simple | `Models/Order.php` mediante `#[Scope]` | Se compone en cualquier consulta Eloquent |
| Listado con 3+ filtros, paginación o múltiples relaciones | `Queries/ListOrders.php` | Se cambia el listado sin tocar el modelo |
| Búsqueda por criterios especiales | `Queries/FindOrderForUser.php` | Una operación de lectura concreta y localizable |
| Reporte agregado / join / ranking | `Queries/OrderSalesReport.php` | Evita contaminar el modelo con reportes |
| Alta, modificación, cancelación, pago | `Actions/*.php` | Regla de negocio; llama a Eloquent / Query cuando corresponda |
| Permisos de acceso | `Policies/OrderPolicy.php` | Autoriza; no reemplaza el filtrado de datos en queries |
| Respuesta y serialización | `Http/Resources/OrderResource.php` | Sin ejecutar nuevas queries |
| Validación de parámetros | `Http/Requests/ListOrdersRequest.php` | Protege los filtros antes de aplicarlos |

## 2. Modelo pequeño con scopes nativos de Laravel 12

**Archivo:** `app/Modules/Orders/Models/Order.php`. El siguiente ejemplo muestra únicamente propiedades y métodos relevantes; se asume un esquema con `tenant_id`, `customer_id` y `status`.

```php
<?php

namespace App\Modules\Orders\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class Order extends Model
{
    protected $fillable = ['tenant_id', 'customer_id', 'status'];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'customer_id');
    }

    #[Scope]
    protected function forTenant(Builder $query, int $tenantId): void
    {
        $query->where('tenant_id', $tenantId);
    }

    #[Scope]
    protected function active(Builder $query): void
    {
        $query->where('status', 'active');
    }
}
```

`#[Scope]` es la sintaxis documentada en Laravel 12 para scopes locales. Los scopes son filtros componibles y **no deben ejecutar la consulta** (`get()`, `first()`, `paginate()`) internamente. Mantener nombres declarativos: `forTenant`, `active`, `ownedBy`.

## 3. Query Object para listar sin agrandar el modelo

**Archivo:** `app/Modules/Orders/Queries/ListOrders.php`.

```php
<?php

namespace App\Modules\Orders\Queries;

use App\Modules\Orders\Models\Order;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

final class ListOrders
{
    public function handle(int $tenantId, ?string $status, int $perPage): LengthAwarePaginator
    {
        return Order::query()
            ->forTenant($tenantId)
            ->when($status !== null, fn (Builder $q) => $q->where('status', $status))
            ->with('customer:id,name')
            ->orderByDesc('id')
            ->paginate(min(max($perPage, 1), 100));
    }
}
```

La query mantiene la condición de tenant **dentro de la consulta SQL**. No cargar todos los registros y filtrar luego con `Collection::filter()`. El `tenantId` debe venir del contexto/autorización del servidor y **nunca** confiar ciegamente en el valor enviado por el cliente. Si la tabla necesita mayor control de columnas, usar `select([...])` reteniendo claves necesarias.

## 4. ¿Cuándo NO crear `Queries/`?

Esto está perfectamente bien en un `Action` **si es una lectura inequívoca y local**:

```php
$order = Order::query()->forTenant($tenantId)->findOrFail($orderId);
```

No crear `FindOrderQuery`, `OrderRepository`, `OrderReader` y `OrderFinder` para una única instrucción. Separar cuando haya filtros, múltiples condiciones de acceso, repetición entre casos o necesidad de pruebas independientes.

## 5. Lecturas y escrituras

- Para escritura usar `Action`; dentro, modificar modelos Eloquent, siempre validando permisos, estado y transacciones.
- No convertir el modelo en un servicio de negocio con `cancelAndRefundAndNotify()`.
- Las consultas de lectura no deben disparar correos, eventos, HTTP externo ni escrituras inesperadas.
- No instalar Repository Pattern como capa obligatoria: Eloquent ya proporciona un modelo de persistencia expresivo. Usar interfaces/adaptadores solamente cuando exista un caso real de sustitución o frontera de infraestructura.
- En dominios multi-tenant, **cada** lista, detalle, actualización y autorización debe aplicar aislamiento por tenant; scopes globales requieren análisis especial y nunca sustituyen pruebas de seguridad.

## 6. Guía para cambios localizados

Si piden «filtrar órdenes por fecha», revisa `ListOrdersRequest` y `Queries/ListOrders.php`; no toques `OrderResource`. Si piden «orden tiene cliente», revisa `Models/Order.php`; si piden «mostrar nombre de cliente», `Query` (eager load) y `Resource`. Si piden «solo dueños pueden ver», `Policy` y query con scope.

**Fuentes Laravel 12:** https://laravel.com/docs/12.x/eloquent , https://laravel.com/docs/12.x/eloquent-relationships , https://laravel.com/docs/12.x/queries .
