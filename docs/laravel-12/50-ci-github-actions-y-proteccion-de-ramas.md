# 50 — Pipeline CI y protección de ramas

## Activación

El ejemplo está en `tooling/github/backend-quality.yml`; **no se ejecuta hasta copiarlo** a `.github/workflows/backend-quality.yml`. Revisa nombre de la rama base, versión PHP, extensiones y requerimientos propios.

Job sugerido: checkout → PHP + Composer → cache de dependencias → install → validate/audit → Pint → Larastan → pruebas.

El workflow de ejemplo configura SQLite en memoria para facilitar el arranque y se limita al caso en que el backend sea compatible con SQLite. Si usa PostgreSQL, extensiones PG y SQL específico, **cambia el servicio de base de datos y credenciales antes de activar**. No sustituyas ese escenario por SQLite como falsa prueba equivalente.

## Reglas de equipo

- Pull request obligatorio; al menos una revisión según riesgo.
- Checks required al estar estabilizados, no antes.
- `CODEOWNERS` para áreas de alto riesgo (auth, pagos, tenants, esquema), plantilla en `tooling/github/CODEOWNERS.example`.
- No deshabilitar test para hacer pasar pipeline; documentar y corregir.
- Despliegue a producción separado de PR; credenciales en secretos del proveedor, permisos mínimos y aprobación donde corresponda.
- Versiones de GitHub Actions externas: revisar releases y fijar commit SHA en repositorios sensibles (evitar suministro malicioso).

## Matriz de pruebas

- SQLite solo para pruebas independientes del motor.
- PostgreSQL/MySQL real para transacciones, índices, locks, JSON, expresiones, constraints, collation y EXPLAIN.
- Redis real para colas/cache si se pretende garantizar su comportamiento.
- Reverb real en e2e/carga según criticidad; `Event::fake()` no comprueba transporte.
