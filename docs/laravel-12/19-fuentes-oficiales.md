# 19 — Fuentes oficiales de Laravel 12

La propuesta aplica la **documentación de Laravel 12**, no pretende imponer una única arquitectura oficial. Laravel permite organizar clases siempre que Composer pueda autoloadearlas.

| Tema | Documentación oficial |
|---|---|
| Estructura | https://laravel.com/docs/12.x/structure |
| Routing y registro | https://laravel.com/docs/12.x/routing |
| Controllers invocables | https://laravel.com/docs/12.x/controllers |
| Contenedor / DI | https://laravel.com/docs/12.x/container |
| Service Providers | https://laravel.com/docs/12.x/providers |
| Form Requests / validación | https://laravel.com/docs/12.x/validation |
| JSON API Resources | https://laravel.com/docs/12.x/eloquent-resources |
| Policies y Gates | https://laravel.com/docs/12.x/authorization |
| Eloquent | https://laravel.com/docs/12.x/eloquent |
| Query Builder | https://laravel.com/docs/12.x/queries |
| Paginación | https://laravel.com/docs/12.x/pagination |
| Índices/migraciones | https://laravel.com/docs/12.x/migrations |
| Relaciones / eager loading | https://laravel.com/docs/12.x/eloquent-relationships |
| Base de datos / transacciones | https://laravel.com/docs/12.x/database |
| Sanctum | https://laravel.com/docs/12.x/sanctum |
| Queues / afterCommit | https://laravel.com/docs/12.x/queues |
| Reverb | https://laravel.com/docs/12.x/reverb |
| Broadcasting / Channels | https://laravel.com/docs/12.x/broadcasting |
| Cache | https://laravel.com/docs/12.x/cache |
| Redis | https://laravel.com/docs/12.x/redis |
| DB performance / logs | https://laravel.com/docs/12.x/database |
| Telescope | https://laravel.com/docs/12.x/telescope |
| Events | https://laravel.com/docs/12.x/events |
| HTTP Client | https://laravel.com/docs/12.x/http-client |
| Tests | https://laravel.com/docs/12.x/testing |
| DB tests | https://laravel.com/docs/12.x/database-testing |
| Pint | https://laravel.com/docs/12.x/pint |
| Errores | https://laravel.com/docs/12.x/errors |
| Producción | https://laravel.com/docs/12.x/deployment |

**Distinguir siempre:** controladores, Requests, Resources, Policies, Service Container, Eloquent y tests son capacidades nativas. Las carpetas `app/Modules`, `Actions`, `Queries`, las bandas de líneas y el protocolo de cambio mínimo son **decisiones de arquitectura del equipo**.

> Nota: Laravel 12 puede aparecer como versión anterior en la página oficial; estos documentos se mantienen deliberadamente enfocados en 12.x. No migrar a otra versión como consecuencia indirecta.

## Criterio de fuentes de performance

El soporte de Eloquent, relaciones, cursores, broadcast y API se referencia en Laravel 12. El **plan SQL, índices especializados, locking y ejecución de migraciones** se deben validar también contra la versión exacta instalada de PostgreSQL o MySQL. Las guías del paquete incluyen recomendaciones de equipo, no promesas universales de latencia.
