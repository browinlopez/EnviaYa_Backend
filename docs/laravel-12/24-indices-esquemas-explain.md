# 24 — Índices, planes de ejecución y diseño de tablas

> **Regla:** el rendimiento es un contrato entre **consulta + esquema + volumen de datos + motor SQL**. No se promete que cambiar Eloquent por SQL nativo haga una consulta más rápida.

## 1. Diseñar desde el patrón de acceso

Para cada endpoint crítico documenta: tabla, volumen actual/proyectado, filtros obligatorios, filtros opcionales, orden, límites, cardinalidad y latencia p95. Luego inspecciona `EXPLAIN`; la migración del índice se hace en el mismo cambio que la consulta cuando es necesaria.

| Patrón de acceso | Índice candidato que se debe evaluar | Precaución |
|---|---|---|
| `tenant_id = ? AND status = ? ORDER BY id DESC` | `(tenant_id, status, id)` | Si `status` es opcional, revisar también el plan sin ese filtro |
| `tenant_id = ? AND created_at >= ? AND created_at < ?` | `(tenant_id, created_at)` | Rangos y orden influyen en el aprovechamiento de otras columnas |
| `customer_id = ? ORDER BY id DESC` | `(customer_id, id)` | No añadir duplicados si ya hay índice adecuado |
| `orders.number = ?` único por tenant | `unique(tenant_id, number)` | La integridad debe imponerse en DB, no solo en validación |
| `order_items.order_id = ?` | índice sobre `order_id` | Revisar FK y motor antes de añadir índices duplicados |

Los índices compuestos tienen orden. Es una hipótesis que **se comprueba**, no una receta universal. En PostgreSQL pueden ser útiles índices parciales / INCLUDE / GIN; en MySQL, índices B-tree, FULLTEXT y determinadas variantes. Su sintaxis y costo se revisan según motor y versión. No copiar un `EXPLAIN` de MySQL como si fuera de PostgreSQL.

## 2. Migración pequeña, explícita y reversible

```php
Schema::table('orders', function (Blueprint $table): void {
    $table->index(['tenant_id', 'status', 'id'], 'orders_tenant_status_id_idx');
});
```

```php
Schema::table('orders', function (Blueprint $table): void {
    $table->dropIndex('orders_tenant_status_id_idx');
});
```

En tablas grandes, **crear un índice puede bloquear o impactar escrituras** según motor y opciones. PostgreSQL admite `CREATE INDEX CONCURRENTLY` con requisitos de ejecución específicos; MySQL tiene mecanismos online/locking dependientes de su versión y DDL. Planifica ventana, tiempo, rollback y monitoreo. No desplegar DDL pesado automáticamente durante horas pico.

## 3. Sargabilidad: evitar envolver columnas indexadas

**Evitar** en una tabla grande indexada por `created_at`:

```php
Order::query()->whereDate('created_at', $date)->get();
```

**Evaluar** un rango semiabierto; generar `$start` y `$end` con timezone definido y transformarlos a la timezone de almacenamiento:

```php
Order::query()
    ->where('created_at', '>=', $start)
    ->where('created_at', '<', $end)
    ->get();
```

`whereDate()` es válido funcionalmente, pero aplicar una función sobre la columna puede impedir aprovechar un índice B-tree convencional. Medir ambos planes en el motor real. Evitar `LIKE '%texto%'` como búsqueda de alto volumen sin un índice/tecnología de búsqueda adecuada; evaluar FULLTEXT, pg_trgm, índice especializado o buscador dedicado según necesidad.

## 4. EXPLAIN y evidencia

- **PostgreSQL:** `EXPLAIN (ANALYZE, BUFFERS) SELECT ...` *ejecuta* la consulta; no correr sobre escrituras peligrosas, ni sin medir impacto en producción.
- **MySQL 8+:** `EXPLAIN FORMAT=JSON SELECT ...`; `EXPLAIN ANALYZE SELECT ...` cuando esté disponible y sea seguro. Confirmar versión y privilegios.
- Evaluar estimación vs filas reales, `rows examined`, tipo de scan, joins, sort, agregación, uso de índice, buffers y duración.
- Usar datos **representativos** de tamaño y distribución; SQLite en tests no reemplaza planes de MySQL/PostgreSQL.
- Evitar recomendar índice nuevo con una sola medición; validar impacto en inserciones, actualizaciones y espacio.

## 5. Modelo y esquema alineados

- Tipos de claves compatibles; no comparar `varchar` con enteros por conversión implícita.
- IDs extranjeros e integridad referencial apropiada.
- FK con índice según requerimientos del motor y consultas efectivas.
- Evitar `SELECT *` en lecturas pesadas; mantener `id`/FK cuando se hidratan relaciones.
- Mantener migraciones **aditivas** cuando sea viable; backfill por lotes y limpieza en despliegues posteriores.

**Fuentes:** https://laravel.com/docs/12.x/migrations , https://laravel.com/docs/12.x/queries , https://laravel.com/docs/12.x/database . Complementar con manual **del motor instalado**.
