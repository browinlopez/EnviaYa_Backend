# 28 — Recetario de consultas rápidas y localizadas

Todas las recetas deben aplicarse dentro de `Models/` (relaciones/scopes) o `Queries/` (listados y reportes), usando parámetros validados y ámbito de tenant impuesto en servidor. Los ejemplos asumen que el modelo y las columnas indicadas existen; ajustar a cada esquema.

## Existencia sin hidratación

```php
$exists = Order::query()->forTenant($tenantId)->where('number', $number)->exists();
```

Para verificar existencia, `exists()` evita materializar registros; **no reemplaza** la autorización cuando hace falta recuperar una entidad.

## Conteos y sumas en DB

```php
$total = Order::query()->forTenant($tenantId)->where('status', 'paid')->count();
$amount = Order::query()->forTenant($tenantId)->where('status', 'paid')->sum('total_amount');
```

Para un dashboard con varios totales, evaluar **una agregación condicional en Query Builder** en vez de varios viajes a la DB, revisando que el plan no implique un scan mayor injustificado. Reportes grandes deben filtrar tenant y rango de tiempo antes de agregar.

## Relaciones selectivas sin N+1

```php
$orders = Order::query()
    ->forTenant($tenantId)
    ->select(['id', 'tenant_id', 'customer_id', 'status'])
    ->with(['customer:id,name'])
    ->withCount('items')
    ->orderByDesc('id')
    ->simplePaginate(30);
```

No eliminar `customer_id`/`id`, necesarias para emparejar resultados relacionados. `simplePaginate` es opción cuando la UI no necesita total de páginas. Si necesitas total exacto, `paginate` puede ser apropiado aunque haga el conteo.

## Paginación por cursor para alto volumen

```php
$orders = Order::query()
    ->forTenant($tenantId)
    ->select(['id', 'tenant_id', 'status', 'created_at'])
    ->orderByDesc('created_at')
    ->orderByDesc('id')
    ->cursorPaginate(30);
```

Revisar índice compatible (p. ej. `(tenant_id, created_at, id)` como **candidato**, no garantía). Orden único, sin `NULL` en columnas ordenadas y sin filtros/columnas incompatibles con las restricciones de cursor pagination. Para scroll grande, comparar p95 vs `OFFSET`.

## Evitar filtros en PHP

```php
// Mal: hidrata datos innecesarios.
$orders = Order::all()->filter(fn ($order) => $order->status === 'paid');

// Bien: filtra en SQL y limita el resultado.
$orders = Order::query()->forTenant($tenantId)
    ->where('status', 'paid')->limit(50)->get();
```

## Agrupación de condiciones `OR` sin escapar al tenant

```php
$orders = Order::query()
    ->forTenant($tenantId)
    ->where(function ($q) use ($term): void {
        $q->where('number', $term)->orWhere('reference', $term);
    })
    ->limit(20)
    ->get();
```

Una llamada `orWhere` fuera de los paréntesis puede ampliar el resultado a **otros tenants**. Cada filtro opcional se valida; ordenar/columnas dinámicas siempre con whitelist, nunca concatenar valores externos en SQL.

## Batch para millones de registros

```php
Order::query()->forTenant($tenantId)
    ->where('status', 'archived')
    ->chunkById(500, function ($orders): void {
        foreach ($orders as $order) {
            // Proceso idempotente y acotado por registro.
        }
    });
```

Usar Jobs cuando el tiempo exceda el presupuesto HTTP. Si la tarea **modifica** columnas involucradas en el filtro, revisar consistencia del lote y evitar cursores que salten filas. En cargas enormes, usar snapshot o watermark de la última clave y checkpoints.

## Lecturas y escrituras masivas

- Para actualizaciones SQL masivas, `update()` en builder evita hidratación, **pero puede omitir eventos de modelo Eloquent** y comportamientos asociados. Documentar invariantes y notificaciones necesarias.
- Para inserción masiva, revisar `insert`, `upsert`, índices únicos, timestamps y eventos esperados; no asumir que equivale a `Model::create()` repetido.
- `firstOrCreate` puede sufrir carreras; poner índice único y manejar conflicto / transacción según el caso.
- `DB::table()` para reportes planos puede ahorrar overhead de modelos; decidir con benchmark.

**Fuentes:** https://laravel.com/docs/12.x/queries , https://laravel.com/docs/12.x/eloquent , https://laravel.com/docs/12.x/eloquent-relationships , https://laravel.com/docs/12.x/pagination .
