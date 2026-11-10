# 06 — Eloquent, consultas y rendimiento

## Models

- Definen tabla cuando no siga convención, relaciones, casts, atributos y scopes breves.
- Proteger *mass assignment* con `$fillable` o estrategia equivalente; pasar solo campos controlados.
- Evitar llamadas a APIs, reportes grandes y flujos multietapa dentro del modelo.

## Consultas

- Consultas simples junto a la acción/endpoint cuando sean evidentes y no se repitan.
- Consultas con filtros múltiples, joins, límites de acceso o reutilización → `Queries/ListOrders.php`, `Queries/Find...`.
- El alcance de acceso es obligatorio en cada lectura/escritura: por usuario, empresa o tenant cuando aplique.
- No hacer `Model::all()` en endpoints con colecciones potencialmente grandes: usar `paginate()` o `cursorPaginate()` según el caso.
- Acotar tamaño máximo permitido para `per_page` y ordenar determinísticamente.

## N+1 y datos innecesarios

- Cargar relaciones con `with()`, `load()` o `withCount()` explícitamente.
- En recursos, usar `whenLoaded` y nunca consultar una relación incidentalmente.
- Considerar `Model::preventLazyLoading(! app()->isProduction())` en un provider **si el equipo acepta esta política**; confirmar efecto en pruebas y jobs.
- Medir consultas e identificar índices según filtros y orden real, no crearlos por intuición.

## Cuidado con `select`

Una optimización de columnas no debe omitir llaves primarias/foráneas necesarias para model binding, relaciones y serialización.

## Consistencia

- Preferir `whereBelongsTo` y relaciones cuando son expresivas.
- Si un `tenant_id` viene del cliente, ignorarlo para filtros de autorización: derivar el scope del contexto autenticado.
- No usar repositorios genéricos como espejo de `find`, `create`, `update` de Eloquent.
