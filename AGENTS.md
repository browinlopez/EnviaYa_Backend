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
12. **Calidad:** mantén las convenciones de Pint y el analizador estático, cuando esté configurado. No edites archivos generados ni lockfiles sin razón.

## Orden de trabajo para una modificación

1. Indica **qué archivo controla la conducta actual** y **qué debe cambiar**.
2. Busca la ruta y las dependencias ascendentes/descendentes.
3. Plantea el menor conjunto de archivos. Si implica migración, contrato API o seguridad, adviértelo.
4. Implementa cambios cohesivos, de preferencia por una sola funcionalidad.
5. Ejecuta tests focalizados; luego Pint y suite relevante. Documenta comandos y resultados reales.
6. Entrega: resumen, rutas de archivos tocados, riesgo, pruebas y posibles pasos de despliegue.

## Prioridad de documentos

- `docs/laravel-12/00-principios.md` — criterios de decisión.
- `docs/laravel-12/17-matriz-de-ubicacion.md` — dónde tocar cada cosa.
- `docs/laravel-12/14-cambios-quirurgicos.md` — cómo modificar sin romper otros módulos.
- Demás documentos por responsabilidad. Ante conflicto, **comportamiento existente + seguridad + test** prevalecen sobre estética o conteo de líneas.

## Prohibiciones explícitas

- Controllers de cientos de líneas; `BaseService`/`BaseRepository` gigantes; `Helpers.php` multifunción.
- Consultas con alcance global que expongan registros de otros usuarios/tenants.
- `env()` fuera de archivos de configuración; `dd()`, `dump()` o secretos en producción.
- `catch (\Throwable)` para silenciar errores o devolver 200 con `success: false`.
- Reestructuración masiva como efecto secundario de un cambio puntual.
- Código o tests inventados como si ya existieran en el repositorio.

**Si una regla no encaja en el código actual, explica la excepción y aplica el cambio menos invasivo.**
