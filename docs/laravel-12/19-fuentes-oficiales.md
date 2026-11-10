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
| Relaciones / eager loading | https://laravel.com/docs/12.x/eloquent-relationships |
| Base de datos / transacciones | https://laravel.com/docs/12.x/database |
| Sanctum | https://laravel.com/docs/12.x/sanctum |
| Queues / afterCommit | https://laravel.com/docs/12.x/queues |
| Events | https://laravel.com/docs/12.x/events |
| HTTP Client | https://laravel.com/docs/12.x/http-client |
| Tests | https://laravel.com/docs/12.x/testing |
| DB tests | https://laravel.com/docs/12.x/database-testing |
| Pint | https://laravel.com/docs/12.x/pint |
| Errores | https://laravel.com/docs/12.x/errors |
| Producción | https://laravel.com/docs/12.x/deployment |

**Distinguir siempre:** controladores, Requests, Resources, Policies, Service Container, Eloquent y tests son capacidades nativas. Las carpetas `app/Modules`, `Actions`, `Queries`, las bandas de líneas y el protocolo de cambio mínimo son **decisiones de arquitectura del equipo**.

> Nota: Laravel 12 puede aparecer como versión anterior en la página oficial; estos documentos se mantienen deliberadamente enfocados en 12.x. No migrar a otra versión como consecuencia indirecta.
