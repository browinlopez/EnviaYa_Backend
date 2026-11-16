# 04 — Capa HTTP: rutas, requests, controllers y resources

## Rutas

- Separar por módulo; utilizar verbos HTTP semánticos, URI plural (`orders`), nombres estables y middleware explícito.
- Aplicar `auth:sanctum` a endpoints privados según la estrategia de autenticación.
- En `routes/api.php` Laravel aplica `/api` de forma predeterminada; usar `v1` para `/api/v1/...`.
- Verificar `php artisan route:list --path=api` después de registrar rutas.

## Controllers

- Preferir un controlador invocable por acción cuando ayude a localizar cambios: `CreateOrderController`, `ShowOrderController`.
- Métodos con solo: recibir request, invocar caso de uso, construir Resource / respuesta.
- Inyectar dependencias por método o constructor; no usar `app()` como localizador de servicios por costumbre.
- Usar model binding y `Gate::authorize` / policies para comprobaciones sensibles.

## FormRequest

- Validar lo que entra, no lo que el frontend promete enviar.
- `rules()` y `authorize()` deben permanecer cohesionados; reglas complejas en `Rule` propias.
- Trabajar con `validated()` o `safe()`; no usar `$request->all()` para crear/actualizar entidades.
- Nunca confiar en `user_id`, `tenant_id`, `role` o `status` enviados por el cliente cuando los asigna el sistema.

## JsonResource

- Salidas seleccionadas explícitamente; no serializar modelos Eloquent completos por defecto.
- Relaciones opcionales: `whenLoaded()`; el Resource no debe cargar relaciones y provocar N+1.
- Usar `Resource::collection()` para listados y paginación compatible con Laravel.
- Un cambio de nombre/tipo de campo es un cambio de contrato: revisar consumidores y pruebas.

## Validación y errores

Laravel envía errores de validación JSON cuando la petición espera JSON. Los endpoints clientes deben enviar `Accept: application/json`. No replicar manualmente validadores, errores 422 y respuestas genéricas salvo decisión documentada de contrato.
