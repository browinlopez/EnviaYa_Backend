# 07 — Base de datos, migraciones y transacciones

## Esquema

- Una migración describe un cambio reversible cuando sea viable; en producción usar enfoque **expand → migrar datos → contraer** para cambios incompatibles.
- Definir índices según filtros, FKs, unicidad y cardinalidad reales.
- No editar migraciones ya aplicadas en entornos compartidos; crear una nueva.
- Seeders productivos idempotentes; factories para pruebas, no datos productivos ocultos.

## Transacciones

Usar `DB::transaction` si **varias escrituras dependientes** necesitan atomicidad. Una sola escritura Eloquent no necesita transacción manual por defecto.

```php
return DB::transaction(function () use ($order, $items): Order {
    $order->save();
    $order->items()->createMany($items);

    return $order;
}, attempts: 3);
```

Los reintentos por deadlock son útiles, pero la closure debe evitar efectos externos no idempotentes (cobros, emails, peticiones HTTP).

## Consistencia de estados

- Para existencias, pagos y operaciones contenciosas, definir invariantes e instrumentar bloqueos (`lockForUpdate` dentro de una transacción) o actualizaciones atómicas según el caso.
- Mantener la transacción corta; no llamar gateways remotos dentro de ella.
- Si se envía un Job que necesita datos recién guardados, usar `afterCommit()` o configuración de cola `after_commit`.
- Para procesos con reintentos (webhooks, pagos), diseñar **idempotencia y restricciones únicas en DB**.

## Cambios peligrosos

Renombrar columnas en producción, borrar datos o introducir `NOT NULL` de golpe exige plan de compatibilidad, backfill y rollback. Toda migración debe revisarse para MySQL/PostgreSQL reales del proyecto, no solo SQLite de pruebas.
