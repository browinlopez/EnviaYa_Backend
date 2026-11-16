# 39 — Migraciones seguras, backfills y cambios compatibles

**Estado:** obligatorio cuando existan datos de usuarios en producción.

## La migración también es una API

Cambiar una columna rompe al código antiguo mientras despliegas o reviertes. Preferir estrategia **expandir → migrar datos → cambiar lectores/escritores → retirar**.

## Regla por cambio de esquema

- Añadir columnas nuevas opcionales y constraints compatibles antes de usarlas.
- Completar datos antiguos con **backfill por lotes**, con checkpoint/reanudación y métricas.
- Cambiar el código para leer/escribir nuevo formato en despliegues coordinados.
- Recién después de validar: eliminar columnas viejas y restricciones transitorias en otro despliegue.
- No modificar migraciones ya ejecutadas en entornos compartidos; añadir migración nueva.
- Distinguir rollback de código de rollback de **datos**: no todo `down()` recupera información destruida.

## Archivos y ubicación

```text
database/migrations/2026_..._add_status_to_orders.php
app/Modules/Orders/Console/BackfillOrderStatus.php   # solo si necesita lógica
app/Modules/Orders/Queries/OrdersToBackfill.php       # consulta compleja opcional
```

## Rendimiento de DDL/DML

- Para índices grandes, revisar operaciones online/concurrentes **específicas del motor**; no asumir que MySQL y PostgreSQL se comportan igual.
- Antes de crear índice: analizar `EXPLAIN`, cardinalidad, costo de escritura y ventanas de bloqueo.
- Evitar actualizar millones de filas en una sola transacción. Paginar por clave monotónica cuando sea seguro.
- Verificar plan de rollback, backup/restauración y monitoreo de locks.
- La migración no debe enviar notificaciones ni invocar HTTP por cada fila.

## Pruebas

- BD vacía y BD con datos anteriores; índices y constraints; ejecución en staging sobre tamaño representativo.
- Deploy con código viejo + nuevo temporalmente coexistiendo cuando corresponda.
- Controlar integridad antes y después del backfill.

**Fuente:** https://laravel.com/docs/12.x/migrations · https://laravel.com/docs/12.x/database
