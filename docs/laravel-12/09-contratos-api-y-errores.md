# 09 — Diseño de contratos HTTP/JSON

## Convenciones

- Base recomendada cuando exista política de versionado: `/api/v1` (no forzar cambio en APIs ya publicadas).
- URIs nominales plurales (`/orders`), verbos HTTP reales.
- Campos JSON consistentes, timestamps ISO 8601, formatos de moneda y zona horaria documentados.
- Paginación, filtros y orden **documentados** y acotados.

## HTTP status

| Escenario | Código típico |
|---|---:|
| Recurso creado | 201 |
| Consulta / actualización con body | 200 |
| Eliminación sin contenido | 204 |
| No autenticado | 401 |
| Sin permisos | 403 (o 404 si se oculta existencia) |
| Recurso inexistente | 404 |
| Validación fallida | 422 |
| Conflicto de estado | 409 |
| Límite de peticiones | 429 |
| Error inesperado | 500 |

## Formato

- Preferir `JsonResource` y colecciones para payloads exitosos; Laravel suele usar `data` y metadatos de paginación.
- Mantener respuesta de validación JSON estándar de Laravel a menos que haya un contrato compartido que exija otra forma.
- No devolver modelos enteros ni `exception->getMessage()` al usuario en errores internos.
- No inventar un «envoltorio universal» distinto para cada módulo.

## Errores de dominio

Para reglas como «orden ya cancelada», lanzar una excepción de dominio específica y mapearla a 409/422 en un punto coherente (excepción con `render`, o configuración de `withExceptions` cuando el proyecto adopte una política común). Evitar muchos handlers duplicados en controllers.

## Compatibilidad

Agregar campos suele ser menos riesgoso que eliminarlos; para cambios incompatibles, versionar, migrar consumidores y documentar fecha de retiro. Mantener pruebas del contrato público, incluidas respuestas de error.
