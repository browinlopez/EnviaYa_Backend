# 01 — Arquitectura de API Laravel 12

## Flujo principal

`HTTP route → FormRequest (autorización básica y validación) → Controller → Action / Query → Eloquent / infraestructura → JsonResource`.

| Componente | Debe hacer | No debe hacer |
|---|---|---|
| Ruta | URI, verbo, nombre, middleware | SQL o decisiones comerciales |
| FormRequest | Entrada, reglas, autorización de solicitud | Guardar registros |
| Controller invocable | Coordinar flujo y respuesta HTTP | Orquestación compleja |
| Action | Operación de negocio y coordinación | Formatear HTTP |
| Query | Consulta legible, permisos de ámbito, filtros | Mutar datos sin intención |
| Eloquent Model | Relaciones, casts, atributos, scopes pequeños | Procesos completos o llamadas HTTP |
| Policy | Decidir permisos | Responder JSON |
| Resource | Exponer contrato JSON | Consultar DB incidentalmente |
| Job / Listener | Side effects asíncronos | Saltarse controles de seguridad |

## Dominio modular, no microservicios

El módulo es una carpeta y una frontera de colaboración, **no** una aplicación Laravel independiente. Usa Eloquent, contenedor, policies, queues y eventos nativos; conserva un solo deploy y esquema de datos mientras sea razonable.

## Dirección de dependencias

- HTTP conoce las `Actions` / `Queries` del módulo.
- Las `Actions` conocen modelos y componentes infraestructurales necesarios.
- Los módulos no importan internals ajenos salvo contratos explícitos.
- `Shared` no referencia `Orders`, `Billing` u otro módulo concreto.

## Regla de simplicidad

Para un endpoint de lectura trivial se permite Controller → Eloquent con autorización y `Resource`, si la consulta cabe cómodamente y no se reutiliza. Extraer `Query` al crecer o reutilizarse. Un sistema profesional no exige una clase por cada línea de código.
