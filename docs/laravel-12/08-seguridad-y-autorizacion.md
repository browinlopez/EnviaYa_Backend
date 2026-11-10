# 08 — Seguridad y autorización para APIs

## Autenticación

- Sanctum es una opción nativa ligera; seleccionar **cookies + CSRF** para SPA propia compatible, o **Bearer token** para clientes móviles/terceros según requisitos.
- No asumir que un token válido tiene permiso para cada operación; autorización de recurso siempre separada.
- Elegir Passport si el producto necesita realmente OAuth2 completo.

## Autorización

- Cada endpoint privado: autenticación; cada recurso o acción sensible: Policy / Gate.
- `FormRequest::authorize()` cubre permiso de la petición; `Gate::authorize('view', $order)` para recurso concreto.
- Model binding **no es autorización**.
- Las Policies de módulos no necesariamente siguen la ruta de autodescubrimiento convencional: registrarlas explícitamente cuando corresponda.

## Multiusuario / multitenancy

- Obtener `tenant_id` del contexto autenticado, no del cuerpo de la solicitud.
- Filtrar listados y búsquedas desde la Query; comprobar ownership al mostrar/actualizar/eliminar.
- No exponer diferencias innecesarias entre recurso inexistente y no accesible si el modelo de amenazas requiere 404.

## Entrada, salida, infraestructura

- `FormRequest`, casting cuidadoso, límites de payload y validación de archivos MIME/tamaño.
- Recursos JSON explícitos; ocultar hashes, tokens, flags internos y campos sensibles.
- Rate limiting en autenticación, endpoints costosos y operaciones sensibles.
- `APP_DEBUG=false` en producción; secretos fuera de Git; rotación de credenciales comprometidas.
- Evitar SQL interpolado, ejecución de comandos a partir de usuario, `unserialize` de datos no confiables y URL remotas sin validar (SSRF).
- Webhooks entrantes: verificar firma, timestamp y duplicados; no fiarse de IP sola.

## Checklist de una ruta nueva

¿Quién se autentica? ¿Quién puede actuar? ¿Qué registro / tenant puede usar? ¿Qué campos puede modificar? ¿Qué límite de frecuencia y tamaño tiene? ¿Qué se devuelve y qué se registra en logs?
