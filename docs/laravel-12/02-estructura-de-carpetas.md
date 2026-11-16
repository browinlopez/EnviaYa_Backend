# 02 — Estructura de carpetas y convenciones

## Convención propuesta para nuevas funcionalidades

```text
app/
├── Modules/
│   ├── Orders/
│   │   ├── Actions/
│   │   │   ├── CreateOrder.php
│   │   │   └── CancelOrder.php
│   │   ├── Http/
│   │   │   ├── Controllers/
│   │   │   │   ├── CreateOrderController.php
│   │   │   │   └── ListOrdersController.php
│   │   │   ├── Requests/
│   │   │   │   └── StoreOrderRequest.php
│   │   │   └── Resources/
│   │   │       └── OrderResource.php
│   │   ├── Models/Order.php
│   │   ├── Queries/ListOrders.php
│   │   ├── Policies/OrderPolicy.php
│   │   ├── Routes/api.php
│   │   ├── Jobs/                  # si aplica
│   │   ├── Events/                # si aplica
│   │   └── Data/                  # si aporta valor
│   └── Billing/...
├── Shared/                       # verdaderamente compartido
└── Providers/AppServiceProvider.php
routes/api.php
database/migrations/              # Laravel estándar
database/factories/               # Laravel estándar
tests/Feature/Orders/
tests/Unit/Orders/
```

Crear **solo las carpetas que se usan**. No son obligatorias `Services`, `Repositories`, `Contracts`, `Data` ni un provider en cada módulo.

## Namespace / Autoload

`app/` ya está mapeada normalmente por Composer como `App\\` a través de PSR-4; `app/Modules/Orders/Actions/CreateOrder.php` usa `namespace App\Modules\Orders\Actions;`. Comprobar `composer.json` en lugar de asumirlo en proyectos existentes.

## Rutas de módulos, enfoque simple

`php artisan install:api` crea soporte de rutas API cuando no está configurado. En Laravel 12, `routes/api.php` se carga con middleware `api` y prefijo `/api` por defecto. **No agregar un segundo `api`**.

```php
// routes/api.php
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->name('api.v1.')->group(function (): void {
    require app_path('Modules/Orders/Routes/api.php');
    require app_path('Modules/Billing/Routes/api.php');
});
```

El archivo `app/Modules/Orders/Routes/api.php` registra sus endpoints y middlewares. Registrar módulos de forma explícita evita discovery mágico; en un sistema grande puede evaluarse registrar rutas de módulo con `Route::group($path)` sin alterar el principio.

## Mantén Laravel estándar donde aporta valor

- `bootstrap/app.php`: configuración del framework, no catálogo de lógica de módulos.
- `bootstrap/providers.php`: registro de providers según documentación.
- `config/`: configuraciones y variables de entorno.
- `database/migrations/`: migraciones cronológicas y seguras.
- `tests/`: PHPUnit / Pest, organizados por funcionalidad.
- `routes/console.php`: tareas de consola cuando se justifique.

Si una estructura existente ya es consistente, **adapta estas convenciones progresivamente**.
