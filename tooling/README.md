# Plantillas para habilitar estándares (NO se instalan automáticamente)

Este directorio contiene **ejemplos revisables**, no archivos obligatorios de configuración.

| Plantilla | Destino después de revisar |
|---|---|
| `quality/phpstan.neon.dist` | `./phpstan.neon.dist` |
| `quality/pint.json` | `./pint.json` |
| `github/backend-quality.yml` | `./.github/workflows/backend-quality.yml` |
| `github/CODEOWNERS.example` | `./.github/CODEOWNERS` (reemplazar propietarios) |

Instalación mínima: `composer require --dev larastan/larastan:^3.0`; comprobar que Laravel Pint está disponible o instalar `composer require --dev laravel/pint`.

La CI de ejemplo asume PHP 8.3, SQLite y que los paquetes de calidad ya están registrados en composer; cambiar a PostgreSQL/MySQL reales si el backend los requiere. Si copias este ZIP a un proyecto existente, **nunca sobreescribas archivos homónimos sin revisar el diff**.
