# 34 — Contratos API, OpenAPI, versionado y compatibilidad

**Estado:** alta prioridad antes de que haya varios frontend/móvil consumiendo la API.

## Contrato como producto

- Documentar método, ruta, autenticación, request, response, códigos HTTP, límites, paginación y ejemplos.
- Guardar definición OpenAPI versionada con el código; no documentar únicamente a mano en un wiki.
- Usar un validador/linter y comprobar divergencias entre contrato e implementación en CI.
- OpenAPI 3.2.1 es una especificación publicada; **elegir 3.1.x como base si el ecosistema de generadores y validadores del equipo aún no soporta 3.2**. Verificar compatibilidad concreta.

## Convención por módulos

```text
openapi/
  openapi.yaml                 # documento raíz o generado desde fragmentos
  schemas/orders.yaml         # esquema de recursos de Orders
  paths/orders.yaml           # endpoints Orders
app/Modules/Orders/Http/
  Controllers/
  Requests/
  Resources/
```

No crear documentación separada para cada campo si una definición única es más legible. Las rutas y esquemas deben poder localizarse por funcionalidad.

## Compatibilidad

- Cambios aditivos: campos opcionales o endpoints nuevos; no romper clientes actuales.
- Cambios potencialmente incompatibles: renombrar/remover campos, cambiar tipos, requisitos, enum o semántica de errores/paginación.
- Mantener `/api/v1` mientras los clientes antiguos lo necesiten; usar `/v2` ante cambios incompatibles deliberados, no por cada feature.
- Respuestas de lista: `data`, metadatos y enlaces coherentes con `JsonResource`/paginadores.
- Nunca filtrar columnas completas con `toArray()` sin revisar exposición de datos; preferir `JsonResource` explícito.
- Ordenar resultados con criterio estable y documentar `per_page` máximo, filtros válidos y cursor cuando aplique.

## Errores

- Usar códigos reales (`400`, `401`, `403`, `404`, `409`, `422`, `429`, `5xx`).
- Mantener formato coherente. RFC 9457 `application/problem+json` es una alternativa **opcional y estándar**; si ya existe contrato estable, migrar controladamente, no mezclar formatos.
- `trace_id`/`request_id` ayuda a diagnosticar sin revelar datos internos, stack traces o SQL.

## Pruebas

- Snapshot/validación de esquema por endpoint y tests de no regresión para clientes existentes.
- Verificar límites, respuestas vacías, formatos de error, rol/tenant y `429`.
- En PRs que alteren OpenAPI: exigir revisión de compatibilidad y aviso a consumidores.

**Fuentes:** https://spec.openapis.org/oas/latest.html · https://www.rfc-editor.org/rfc/rfc9457 · https://laravel.com/docs/12.x/eloquent-resources
