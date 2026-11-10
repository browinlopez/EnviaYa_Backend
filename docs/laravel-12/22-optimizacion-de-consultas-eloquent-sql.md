# 22 — Consultas de alto rendimiento: Eloquent, Builder, SQL e índices

## Política de selección de herramienta

| Caso | Primero considerar | Escalar a |
|---|---|---|
| CRUD y relaciones | **Eloquent** | Query Object cuando crece |
| Filtros y listados | **Eloquent Builder** (`where`, `when`, `with`) | Query dedicado |
| Resúmenes agregados | `withCount`, `withSum`, `selectRaw` con bindings | `DB::table()` para reportes planos |
| Informes masivos / joins especializados | Query Builder de Laravel | SQL parametrizado si el optimizador lo exige |
| Consultas muy específicas de motor | SQL con bindings y tests del motor | Nunca interpolación de input |

No existe un método universalmente «más rápido». `DB::table()` evita hidratar modelos y puede ahorrar memoria en reportes, mientras que Eloquent facilita relaciones, casts, scopes y restricciones consistentes. **Decidir con `EXPLAIN` y mediciones**, no por preferencias estéticas.

## 1. Evitar N+1 y consultas ocultas

**Antipatrón:** recorrer `$orders` y acceder a `$order->customer` sin haber precargado esa relación. Para 100 órdenes, puedes generar una consulta de lista y hasta 100 adicionales.

**Preferir:**

```php
$orders = Order::query()
    ->with('customer:id,name')
    ->orderByDesc('id')
    ->paginate(30);
```

El recurso JSON debe usar `whenLoaded('customer')` cuando la relación sea opcional, sin ejecutar queries accidentalmente. Para contar relacionados, preferir `withCount('items')` frente a traer todos los `items` y contarlos en PHP.

```php
$orders = Order::query()
    ->withCount('items')
    ->withExists('payments')
    ->paginate(30);
```

En `select` parciales conservar `id` y claves foráneas necesarias; de lo contrario las relaciones pueden fallar. Para filtrar por relación usa `whereHas`, `whereRelation`, `whereBelongsTo` o `withWhereHas` según la semántica.

## 2. Consultar en la DB, no en memoria

| Evitar | Preferir |
|---|---|
| `Order::all()->where('status', 'paid')` | `Order::where('status', 'paid')->paginate(30)` |
| `Order::all()->count()` | `Order::query()->count()` |
| `Order::where(...)->get()->isNotEmpty()` | `Order::where(...)->exists()` |
| `Customer::all()->filter(...)` | Predicados `where` parametrizados |
| Traer miles de filas para sumarlas en PHP | `sum()`, `avg()`, `groupBy()` en la DB |

**Importante:** `get()` es correcto para conjuntos pequeños y acotados; la regla es no materializar volúmenes desconocidos para después filtrar.

## 3. Filtros, orden y paginación

- Limitar `per_page` del cliente (por ejemplo 1–100) y validar listas permitidas de `sort` y `direction` antes de usarlas en `orderBy`.
- `paginate()` incluye conteo total, útil cuando la UI necesita páginas numeradas; `simplePaginate()` evita ese conteo si no se necesita; `cursorPaginate()` puede rendir mejor en listas grandes con orden estable e indexado.
- Para cursor pagination, seleccionar un orden compatible y determinista (por ejemplo, `created_at` + `id`) y comprobar las restricciones del método en la documentación; no garantizar estabilidad con una columna nula o no única.
- Evitar `OFFSET` creciente para scroll infinito masivo cuando sea viable un cursor.
- No permitir ordenar por columnas arbitrarias recibidas del usuario: valores de columnas / identificadores SQL **no** se protegen como valores parametrizados.

## 4. Índices diseñados para consultas reales

Dado un listado `WHERE tenant_id = ? AND status = ? ORDER BY id DESC`, evaluar un índice compuesto orientado a esos predicados, con `EXPLAIN`, cardinalidad y distribución reales. No añadir un índice por cada columna sin criterio: ocupan disco y penalizan escrituras.

```php
Schema::table('orders', function (Blueprint $table): void {
    $table->index(['tenant_id', 'status', 'id'], 'orders_tenant_status_id_idx');
});
```

El ejemplo es una **candidata a probar**, no una recomendación universal. Para filtros por fechas sobre índices B-tree, suele convenir utilizar rangos (`>=`, `<`) en lugar de envolver la columna indexada en funciones; revisar por motor PostgreSQL/MySQL y tipo de fecha/zona horaria.

## 5. SQL seguro y mínimo

```php
$totals = DB::table('orders')
    ->select('tenant_id')
    ->selectRaw('SUM(total_amount) as total')
    ->where('tenant_id', $tenantId)
    ->groupBy('tenant_id')
    ->first();
```

Las condiciones de valores deben usar bindings (`where`, placeholders `?`). Si el nombre de la columna o dirección de orden viene del cliente, resolverlo con un **mapa permitido de columnas**; nunca concatenarlo directamente. Una consulta SQL nativa se justifica si resuelve claramente una limitación de expresividad/rendimiento, documentando la razón.

## 6. Medición obligatoria ante una optimización

1. Capturar SQL efectivo, bindings **redactados**, duración y plan `EXPLAIN` en ambiente seguro.
2. Medir número de queries, tiempo p50/p95, filas y memoria antes del cambio.
3. Revisar N+1, índices, tamaño del `SELECT`, cardinalidad, transacciones y caché.
4. Optimizar **una causa concreta**; correr tests y repetir medición con datos representativos.
5. Registrar benchmark/plan y rollback en PR cuando haya un cambio sensible de índice o query.

En `AppServiceProvider`, bajo configuración adecuada, `DB::listen` y `DB::whenQueryingForLongerThan` ayudan a observar; no registrar datos sensibles ni activar SQL logging indiscriminado en producción. `Model::preventLazyLoading(! app()->isProduction())` detecta consultas ocultas durante desarrollo/pruebas.

## 7. Concurrencia, caché y consistencia

- `DB::transaction()` para varios cambios que deben ser atómicos.
- `lockForUpdate()` dentro de transacción cuando se compite por una misma fila; cuidado con deadlocks y orden de locks.
- Usar `chunkById` / `lazyById` para procesar conjuntos grandes sin agotamiento de memoria; cuidado con mutaciones y filtros.
- Cachear datos realmente costosos y estables con claves que incluyen tenant/usuario cuando aplique. Definir TTL e invalidación; **la caché no autoriza** y no debe mezclar tenants.
- Eventos WebSocket no reemplazan las escrituras confirmadas: la DB es fuente de verdad.

**Fuentes Laravel 12:** https://laravel.com/docs/12.x/queries , https://laravel.com/docs/12.x/eloquent-relationships , https://laravel.com/docs/12.x/database , https://laravel.com/docs/12.x/pagination .
