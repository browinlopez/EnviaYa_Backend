# AGENTS.md — Laravel 12 Backend API

## Alcance

Repositorio de **API Laravel 12**. Prioridad: cambios pequeños, módulos claros, tests y contratos estables. Sin Blade, frontend ni MVC tradicional de vistas. **Estas son convenciones del proyecto, no requisitos intrínsecos del framework.**

> **Antes de aplicar nada de esto, lee
> [`docs/laravel-12/EXCEPCIONES.md`](docs/laravel-12/EXCEPCIONES.md).**
>
> Este repositorio existía antes que el estándar. El registro dice qué reglas
> se cumplen, cuáles no y por qué, con los números medidos. La más importante:
> **la estructura NO es `app/Modules/`** y no se empieza a crear hoy — el
> código vive en `app/Http/Controllers/<Dominio>/`, `app/Services/` y
> `app/Models/<Dominio>/`, y montar una segunda estructura al lado es peor que
> cualquiera de las dos.

## Instrucciones de cumplimiento obligatorio

1. **Entiende antes de editar:** inspecciona la estructura existente, rutas, tests, migraciones, proveedores y convenciones. Identifica módulo, capa y alcance del cambio.
2. **Cambio mínimo verificable:** modifica solo los archivos involucrados; no reformatees ni refactorices carpetas ajenas; conserva contratos públicos salvo petición explícita.
3. **Modular por funcionalidad:** nuevas clases de negocio en `app/Modules/<Modulo>/...`; código compartido en `app/Shared/` **solo cuando haya reutilización real**.
4. **Una responsabilidad por archivo:** controllers invocables y delgados, `FormRequest` para validación, `JsonResource` para salida, `Action` para caso de uso, `Query` para consultas complejas, `Policy` para permisos.
5. **Evita ceremonias:** no inventes `Repository`, `Interface`, `DTO`, `Service`, `Trait`, provider o abstracción por costumbre. Introduce uno cuando exista una necesidad verificable.
6. **Archivos breves, sin code golf:** aplicar los objetivos de `docs/laravel-12/03-limites-de-lineas.md`. Si exceden, revisar cohesión; no fraccionar métodos por un límite numérico.
7. **No lógica de negocio en rutas, controllers, requests, resources ni middleware.** Los modelos describen datos y relaciones; no coordinan flujos grandes.
8. **Seguridad:** `auth:sanctum` según el cliente, policies, autorización por registro/tenant, datos validados, consultas con ámbito y ningún secreto en logs o respuestas.
9. **API compatible:** rutas versionadas cuando se acuerde, respuestas previsibles, códigos HTTP reales, sin convertir todos los errores en 200, paginación en listados.
10. **Datos seguros:** migraciones aditivas, índice apropiado, transacciones cuando haya operaciones dependientes, idempotencia en operaciones con reintentos.
11. **Pruebas:** agrega o modifica pruebas enfocadas en la funcionalidad, incluyendo error, permisos y límites de datos. No afirmes pruebas ejecutadas si no se ejecutaron.
12. **Consultas rápidas (obligatorio):** Eloquent/Builder por defecto; relaciones y scopes breves en `Models/`, consultas complejas en `Queries/`. Queries del módulo filtran en SQL por tenant/usuario, evitan N+1, limitan filas y columnas, validan sort, paginan y utilizan índices comprobados con `EXPLAIN`. Revisar `21`–`26`, `28` y `29`.
13. **Medir antes de optimizar:** en lecturas críticas medir latencias p50/p95, SQL count, duración total, memoria y plan de ejecución; no asegurar ganancia sin prueba comparable. Revisar `24`, `25` y `29`.
14. **WebSocket Reverb:** eventos de negocio mínimos por módulo, canales privados autorizados por registro/tenant, `ShouldDispatchAfterCommit`, workers supervisados, reconexión por HTTP, tests de permisos. Revisar `23`, `27`, `30` y `31`.
15. **Calidad:** mantén las convenciones de Pint y el analizador estático, cuando esté configurado. No edites archivos generados ni lockfiles sin razón.

## Contratos, confiabilidad y seguridad ampliados

16. **Autenticación y tenant:** distinguir SPA con cookies de tokens de móvil e integraciones; ninguna autorización se resuelve solo con autenticar. Documentos `32`, `33`, `35`.
17. **Contrato como código:** mantener OpenAPI/esquemas y compatibilidad de consumidores en cambios de rutas, requests, resources, errores o paginación. Documento `34`.
18. **HTTP/webhooks externos:** timeout, reintentos seguros, firma, deduplicación y validación de respuestas. No reintentar un POST con efectos sin idempotencia. Documento `36`.
19. **Cambios con consecuencias persistentes:** evaluar constraints, transacciones, claves idempotentes y outbox únicamente si existe riesgo verificable de efectos perdidos o duplicados. Documento `37`.
20. **Trabajo asíncrono:** jobs idempotentes, `afterCommit` según contrato, límites de reintento, colas supervisadas, scheduler sin solapamiento. Documento `38`.
21. **Esquema y archivos:** migraciones compatibles, backfill por lotes; storage privado y parsing protegido. Documentos `39`, `40`.
22. **Calidad y operaciones:** Pint, análisis estático configurado, Composer audit, tests de contrato/concurrencia, observabilidad y deploy seguro. Documentos `41`–`47`.
23. **Progresividad:** no instalar Horizon, Telescope, Outbox, broker adicional, interfaces ni paquetes por cumplir un documento; justificar necesidad real y costo operativo.

## Orden de trabajo para una modificación

1. Indica **qué archivo controla la conducta actual** y **qué debe cambiar**.
2. Busca la ruta y las dependencias ascendentes/descendentes.
3. Plantea el menor conjunto de archivos. Si implica migración, contrato API o seguridad, adviértelo.
4. Implementa cambios cohesivos, de preferencia por una sola funcionalidad.
5. Ejecuta tests focalizados; luego Pint y suite relevante. Documenta comandos y resultados reales.
6. Entrega: resumen, rutas de archivos tocados, riesgo, pruebas y posibles pasos de despliegue.

## Rendimiento como criterio de revisión

- **Lecturas:** primero `Models/` (relaciones/scopes), después `Queries/` (filtros/listados/reportes), `24` (índices/EXPLAIN), `25` (benchmark), `28` (recetas).
- **Realtime:** `23` (Reverb), `27` (canales, seguridad, eventos), `30` (operación), `31` (caso integrado), `45` (presencia, escala, reconexión).
- Para toda mejora de consultas, completar `29-checklist-pr-rendimiento.md`. Preferir cambios aislados con rollback.

## Prioridad de documentos

- `docs/laravel-12/00-principios.md` — criterios de decisión.
- `docs/laravel-12/17-matriz-de-ubicacion.md` — dónde tocar cada cosa.
- `docs/laravel-12/14-cambios-quirurgicos.md` — cómo modificar sin romper otros módulos.
- `docs/laravel-12/47-ruta-de-madurez-y-auditoria.md` — implementación escalonada y revisión por funcionalidad.
- Demás documentos por responsabilidad. Ante conflicto, **comportamiento existente + seguridad + test** prevalecen sobre estética o conteo de líneas.

## Prohibiciones explícitas

- Controllers de cientos de líneas; `BaseService`/`BaseRepository` gigantes; `Helpers.php` multifunción.
- Consultas con alcance global que expongan registros de otros usuarios/tenants.
- `Model::all()` en listados no acotados, `->get()->count()` cuando basta `count()`, queries dentro de `Resource`, relaciones lazy-loading en bucles, filtros enormes en Collection después de cargar DB.
- Crear índices por intuición, `LIKE '%texto%'` masivo sin estrategia, paginación ilimitada, `orderBy($input)` sin whitelist o mezclar datos de tenants en caché.
- Usar WebSockets como DB o publicar cambios antes del commit; canales públicos con datos privados; dependencias pesadas en autorizaciones de canales.
- `env()` fuera de archivos de configuración; `dd()`, `dump()` o secretos en producción.
- `catch (\Throwable)` para silenciar errores o devolver 200 con `success: false`.
- Reestructuración masiva como efecto secundario de un cambio puntual.
- Código o tests inventados como si ya existieran en el repositorio.

**Si una regla no encaja en el código actual, explica la excepción y aplica el cambio menos invasivo.**

## Estándar final y verificación automatizada

24. Consultar `48`–`56` antes de activar cambios globales de pipeline, análisis estático, tests de carga o despliegue.
25. Las plantillas de `tooling/` son **optativas** y deben adaptarse al proyecto, versión de PHP y motor de base de datos. No sobrescribir configuraciones existentes automáticamente.
26. Todos los PR deben mostrar evidencia ejecutada de calidad, tests y alcance. No declarar que un check pasó si no se corrió.
27. Los verificadores en `scripts/` son heurísticas no bloqueantes inicialmente; la cohesión y la seguridad prevalecen sobre un límite arbitrario.
28. Aplicar `56-definicion-de-terminado-auditable.md` de forma proporcional a la funcionalidad, sin generar capas ni tests que no aporten valor.
