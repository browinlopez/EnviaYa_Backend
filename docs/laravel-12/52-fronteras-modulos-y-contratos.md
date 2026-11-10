# 52 — Fronteras entre módulos

Para prevenir dependencias cruzadas impredecibles:

- `app/Modules/Orders/` contiene sus entradas HTTP, acciones, consultas, eventos y modelos propios.
- En un módulo `A`, no importar detalles internos (`Actions/`, `Queries/`, `Http/`, `Models/`) de `B` por conveniencia.
- Publicar contratos acotados (p. ej. `App\\Modules\\Billing\\Contracts\\...`), eventos explícitos o servicios de aplicación estrechos cuando exista interacción necesaria. Es una convención del equipo.
- `Shared/` solo para conceptos estables y realmente compartidos; nunca el contenedor de todo lo no clasificado.
- No crear interfaces vacías o DTO duplicados por disciplina estética.

`python3 scripts/check_module_boundaries.py --root .` detecta `use App\\Modules\\OtroModulo\\...` entre módulos como alerta rápida y admite `--fail` para bloquearlo. **No** analiza servicios resueltos dinámicamente, FQCN en strings, rutas, callbacks, relaciones Eloquent ni autoload. Completar con revisión manual y tests.

Una excepción debe tener razón, owner, contrato y test; no debe convertirse en supresión global del análisis.
