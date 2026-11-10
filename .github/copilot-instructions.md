# Instrucciones Copilot — Laravel 12 API

Lee `AGENTS.md` antes de escribir o refactorizar. Es la fuente principal de convenciones del proyecto.

- Trabaja por módulo `app/Modules/<Feature>/` y modifica **el menor conjunto de archivos**.
- Controller invocable delgado; FormRequest valida; Action coordina lógica; Query consulta; Resource define JSON; Policy autoriza.
- **No** agregar Repository, DTO, Service, interface ni carpetas vacías por rutina.
- Archivos pequeños pero claros: consultar `docs/laravel-12/03-limites-de-lineas.md`.
- Ver dónde ubicar cambios en `docs/laravel-12/17-matriz-de-ubicacion.md`.
- Antes de cambiar: ubicar ruta, módulo, contrato, prueba y permisos.
- Después: tests focalizados, `./vendor/bin/pint --test` y diff revisado; reportar solo ejecuciones reales.
- Nunca introducir cambios de contrato, exposición cross-tenant, secretos o refactors masivos sin razón explícita.
