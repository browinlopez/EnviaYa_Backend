# 17 — Matriz de localización: ¿dónde modificar?

| Necesitas cambiar... | Archivo primero | En ocasiones también |
|---|---|---|
| URL/verbo/middleware | `Modules/X/Routes/api.php` | `routes/api.php` al crear módulo |
| Permiso para ejecutar | `Modules/X/Policies/*Policy.php` | Request, provider, test |
| Validación de entrada | `Modules/X/Http/Requests/*Request.php` | Rule, test |
| Campos JSON de salida | `Modules/X/Http/Resources/*Resource.php` | Contrato, test |
| Regla de negocio | `Modules/X/Actions/*Action.php` | Excepción, test |
| Query, filtro, orden | `Modules/X/Queries/*.php` | Scopes modelo, índices, Request, test |
| Relación/filtrado reutilizable pequeño | `Modules/X/Models/*.php` (`#[Scope]`) | Query que lo usa, test |
| Consulta lenta / N+1 | `Modules/X/Queries/*.php` + `Models/*.php` | `24`, `25`, índice y benchmark |
| Índice / esquema | `database/migrations/*.php` | `Queries`, `EXPLAIN`, operación |
| Cache de reporte costoso | Application Action / Query wrapper focalizado | TTL, Redis, invalidación y seguridad |
| Canal privado Reverb | `Modules/X/Broadcasting/*Channel.php` | `routes/channels.php`, test de auth |
| Evento realtime de negocio | `Modules/X/Events/*Changed.php` | `Actions`, queue, contrato y tests |
| Relaciones y casts | `Modules/X/Models/*.php` | migración, factory, test |
| Nuevo dato en DB | `database/migrations/*.php` | model, factory, Request, Resource |
| Envío de correo asíncrono | `Modules/X/Jobs/*.php` | Action / evento / test |
| Respuesta de error uniforme | `bootstrap/app.php` / excepción dedicada | test de contrato |
| Autenticación | Config y middleware existentes / Sanctum | rutas, pruebas de seguridad |
| Integración proveedor | `Modules/X/Integrations/*` o módulo técnico | `config/services.php`, tests |
| Reporte o exportación | `Modules/X/Queries/*`, Action/Job | test y almacenamiento |
| Comportamiento entre módulos | Actions / contratos explícitos | ADR breve, tests de integración |

## Ruta corta de investigación

1. `php artisan route:list --path=api`.
2. Buscar controller o nombre de ruta con el buscador del editor.
3. Seguir dependencia al Action / Query.
4. Verificar el test bajo `tests/Feature/<Modulo>/`.
5. `git grep` de campo / clase si el efecto no es evidente.

## Regla de propiedad

Nunca coloques una regla en `Shared` solo porque es usada por dos controllers del **mismo** módulo: sigue perteneciendo al módulo. `Shared` es para necesidades genuinamente transversales.
