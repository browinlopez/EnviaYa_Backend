# 33 — Aislamiento multi-tenant, roles y autorización por registro

**Estado:** obligatorio si el backend atiende múltiples empresas, puntos de venta o clientes.

## Tres controles distintos

1. **Identidad**: ¿quién invoca? Middleware de autenticación.
2. **Ámbito**: ¿qué registros puede ver? Filtrar con ID del tenant resuelto del contexto confiable.
3. **Permiso**: ¿qué operación puede ejecutar? Policy/gate por usuario, recurso y operación.

No usar `tenant_id` enviado por el frontend como fuente de verdad sin validar pertenencia activa. Resolver tenant desde contexto autenticado y membresía verificada, incluso en jobs y eventos.

## Organización

```text
app/Modules/Organizations/
  Actions/ResolveActiveOrganization.php   # cuando existe lógica de selección
  Models/Organization.php
  Policies/OrganizationPolicy.php
app/Modules/Orders/
  Models/Order.php                         # belongsTo Organization, scope forTenant
  Queries/ListOrders.php                  # filtra antes de paginar
  Policies/OrderPolicy.php                 # view, create, update, delete
```

## Consulta segura

```php
$order = Order::query()
    ->where('organization_id', $organization->getKey())
    ->findOrFail($orderId);

Gate::authorize('view', $order);
```

**Condición:** `$organization` fue resuelta/verificada contra el usuario autenticado antes de ejecutar esta consulta.

## Riesgos y reglas

- No cargar `Order::find($id)` y filtrar después en PHP: el límite de acceso se impone en SQL.
- `Policy` controla la acción; un *scope* del tenant controla visibilidad. Se complementan, no se sustituyen.
- Para referencias a hijos, validar tenant también en relaciones, actualización masiva y operaciones `delete`.
- Para IDs globales/UUIDs aplicar la misma autorización; UUID no es mecanismo de seguridad.
- Claves compuestas y constraints ayudan a preservar integridad; evaluar según modelo relacional real.
- En cache usar claves con tenant, rol/permiso cuando sea relevante, versión y filtros; invalidar al cambiar permisos o datos.
- En Reverb, canales privados + callback de autorización por tenant y entidad; no propagar datos personales como nombres de canal.
- En jobs cargar contexto explícito del tenant; no depender de `request()`, auth del proceso o estado global residual.

## Tests indispensables

Tabla de al menos cuatro identidades: owner, miembro permitido, miembro sin permiso y usuario de otro tenant. Probar GET/POST/PATCH/DELETE, exportaciones, búsquedas, URLs de archivos y autorización de canales.

**Fuentes:** https://laravel.com/docs/12.x/authorization · https://api-security.owasp.org/editions/2023/en/0x11-t10/
