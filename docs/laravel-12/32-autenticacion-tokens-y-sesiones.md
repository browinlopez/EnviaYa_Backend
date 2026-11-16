# 32 — Autenticación y ciclo de vida de credenciales

**Estado:** prioridad crítica para cualquier API con usuarios.

## Decide según el cliente real

| Consumidor | Enfoque a evaluar | Precaución |
|---|---|---|
| SPA propia, mismo dominio raíz o configuración compatible | Sanctum basado en sesión/cookies | Configurar `stateful`, cookies seguras, CSRF y CORS concretos |
| App móvil propia | Tokens Sanctum, expiración y revocación según amenazas | Almacenamiento seguro del token en el dispositivo |
| Integración servidor-servidor | Credencial de alcance mínimo o OAuth2 según contexto | Rotación, auditoría, cuota, identificación del cliente |
| Plataforma OAuth2 compleja | Solución OAuth2/OIDC verificada | No desarrollar OAuth artesanal |

La documentación de Sanctum recomienda usar sesiones/cookies para una SPA *first-party*, no tokens API; no imponer un único mecanismo de autenticación a todos los consumidores.

## Ubicación modular

- `app/Modules/Auth/Http/Controllers/`: login/logout/rotación de credenciales, un caso de uso por controller.
- `app/Modules/Auth/Http/Requests/`: sintaxis y formato de la entrada.
- `app/Modules/Auth/Actions/`: políticas de revocación e inicio de sesión cuando haya lógica.
- `app/Modules/Auth/Policies/` o `app/Policies/`: autorización de recursos, separada de la autenticación.
- `config/sanctum.php` y `config/cors.php`: configuración cuando aplique; no hardcodear dominios.

## Reglas obligatorias

1. Autenticar **no** concede acceso automático a un registro: ejecutar Policy y restringir por tenant/owner.
2. Almacenar credenciales únicamente en secretos de configuración, nunca en el repositorio ni en logs.
3. Decidir duración, revocación al cerrar sesión, rotación, límites por dispositivo, bloqueo progresivo y recuperación de cuenta.
4. Aplicar `throttle` distinto a login, registro, recuperación y rutas de uso intensivo.
5. Probar 401 sin credenciales; 403 sin permiso; 404 cuando se pretenda ocultar existencia de un recurso fuera del ámbito.
6. No considerar `tokenCan()` como único control de permisos en peticiones SPA first-party: las Policies siguen siendo necesarias.
7. Revisar MFA, auditoría y rotación cuando el riesgo del negocio lo justifique.

## Casos de prueba mínimos

- Token expirado/revocado; cookie sin contexto válido; logout; credenciales incorrectas; rate limit.
- Usuario A intenta operar recurso de B; usuario de otra empresa reutiliza un ID válido.
- Cambio de rol mientras hay una sesión/token activo: documentar cuándo impacta los permisos.

**Fuente:** https://laravel.com/docs/12.x/sanctum · https://laravel.com/docs/12.x/authorization · https://laravel.com/docs/12.x/rate-limiting
