# 16 — Ejemplo completo: POST /api/v1/orders (Laravel 12)

Ejemplo de **módulo pequeño e implementable**, sin Repository, DTO ni Service genéricos. Se asume Laravel 12, Sanctum instalado (`php artisan install:api` si falta), un modelo `App\Models\User` y la migración indicada. Adaptar nombres, autorizaciones y datos al negocio real. Aquí la creación se permite para usuarios autenticados y cada consulta a una orden individual requiere ser propietario.

## 1. `routes/api.php`

> Si este archivo ya tiene otras rutas, no lo reemplaces; añade la carga del módulo dentro del grupo que corresponda.

```php
<?php

use Illuminate\Support\Facades\Route;

Route::prefix('v1')->name('api.v1.')->group(function (): void {
    require app_path('Modules/Orders/Routes/api.php');
});
```

## 2. `app/Modules/Orders/Routes/api.php`

```php
<?php

use App\Modules\Orders\Http\Controllers\CreateOrderController;
use App\Modules\Orders\Http\Controllers\ListOrdersController;
use App\Modules\Orders\Http\Controllers\ShowOrderController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth:sanctum')->prefix('orders')->name('orders.')->group(function (): void {
    Route::get('', ListOrdersController::class)->name('index');
    Route::post('', CreateOrderController::class)->name('store');
    Route::get('/{order}', ShowOrderController::class)->name('show');
});
```

> Verificar las URIs finales mediante `php artisan route:list --path=api`; no duplicar el prefijo `/api`.

## 3. `app/Modules/Orders/Http/Requests/StoreOrderRequest.php`

```php
<?php

namespace App\Modules\Orders\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class StoreOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'description' => ['required', 'string', 'max:255'],
            'quantity' => ['required', 'integer', 'min:1', 'max:1000'],
        ];
    }
}
```

## 4. `app/Modules/Orders/Actions/CreateOrder.php`

```php
<?php

namespace App\Modules\Orders\Actions;

use App\Models\User;
use App\Modules\Orders\Models\Order;

final class CreateOrder
{
    public function handle(User $actor, array $data): Order
    {
        return Order::query()->create([
            'user_id' => $actor->getKey(),
            'description' => $data['description'],
            'quantity' => $data['quantity'],
        ]);
    }
}
```

Solo una escritura, **no hace falta `DB::transaction()`** en este caso.

## 5. `app/Modules/Orders/Http/Controllers/CreateOrderController.php`

```php
<?php

namespace App\Modules\Orders\Http\Controllers;

use App\Modules\Orders\Actions\CreateOrder;
use App\Modules\Orders\Http\Requests\StoreOrderRequest;
use App\Modules\Orders\Http\Resources\OrderResource;
use Illuminate\Http\JsonResponse;

final class CreateOrderController
{
    public function __invoke(StoreOrderRequest $request, CreateOrder $create): JsonResponse
    {
        $order = $create->handle($request->user(), $request->validated());

        return (new OrderResource($order))->response()->setStatusCode(201);
    }
}
```

## 6. `app/Modules/Orders/Http/Resources/OrderResource.php`

```php
<?php

namespace App\Modules\Orders\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class OrderResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'description' => $this->description,
            'quantity' => $this->quantity,
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}
```

## 7. `app/Modules/Orders/Models/Order.php`

```php
<?php

namespace App\Modules\Orders\Models;

use Illuminate\Database\Eloquent\Model;

class Order extends Model
{
    protected $fillable = ['user_id', 'description', 'quantity'];

    protected function casts(): array
    {
        return ['quantity' => 'integer'];
    }
}
```

> Las migraciones se guardan en `database/migrations/`, según convención Laravel.

## 8. `database/migrations/YYYY_MM_DD_HHMMSS_create_orders_table.php`

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('orders', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('description');
            $table->unsignedInteger('quantity');
            $table->timestamps();
            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('orders');
    }
};
```

El nombre cronológico del archivo lo genera Artisan; no usar literalmente `YYYY_MM_DD_HHMMSS`.

## 9. `app/Modules/Orders/Policies/OrderPolicy.php`

```php
<?php

namespace App\Modules\Orders\Policies;

use App\Models\User;
use App\Modules\Orders\Models\Order;

final class OrderPolicy
{
    public function view(User $user, Order $order): bool
    {
        return (int) $user->getKey() === (int) $order->user_id;
    }
}
```

## 10. Registrar policy en `app/Providers/AppServiceProvider.php`

Agregar importaciones y esta línea a su método `boot()` **existente**; no reemplazar el provider completo:

```php
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Policies\OrderPolicy;
use Illuminate\Support\Facades\Gate;

// Dentro de boot():
Gate::policy(Order::class, OrderPolicy::class);
```

## 11. `app/Modules/Orders/Http/Controllers/ShowOrderController.php`

```php
<?php

namespace App\Modules\Orders\Http\Controllers;

use App\Modules\Orders\Http\Resources\OrderResource;
use App\Modules\Orders\Models\Order;
use Illuminate\Support\Facades\Gate;

final class ShowOrderController
{
    public function __invoke(Order $order): OrderResource
    {
        Gate::authorize('view', $order);

        return new OrderResource($order);
    }
}
```


## 11A. `app/Modules/Orders/Http/Requests/ListOrdersRequest.php`

```php
<?php

namespace App\Modules\Orders\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class ListOrdersRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return ['per_page' => ['sometimes', 'integer', 'min:1', 'max:100']];
    }
}
```

## 11B. `app/Modules/Orders/Queries/ListOrders.php`

```php
<?php

namespace App\Modules\Orders\Queries;

use App\Models\User;
use App\Modules\Orders\Models\Order;
use Illuminate\Pagination\LengthAwarePaginator;

final class ListOrders
{
    public function handle(User $actor, int $perPage): LengthAwarePaginator
    {
        return Order::query()
            ->where('user_id', $actor->getKey())
            ->latest('id')
            ->paginate($perPage);
    }
}
```

La Query **aplica el scope de acceso dentro de la consulta**, no después de recuperar datos.

## 11C. `app/Modules/Orders/Http/Controllers/ListOrdersController.php`

```php
<?php

namespace App\Modules\Orders\Http\Controllers;

use App\Modules\Orders\Http\Requests\ListOrdersRequest;
use App\Modules\Orders\Http\Resources\OrderResource;
use App\Modules\Orders\Queries\ListOrders;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

final class ListOrdersController
{
    public function __invoke(ListOrdersRequest $request, ListOrders $query): AnonymousResourceCollection
    {
        $orders = $query->handle($request->user(), $request->integer('per_page', 15));

        return OrderResource::collection($orders);
    }
}
```

## 11D. `tests/Feature/Orders/ListOrdersTest.php`

```php
<?php

namespace Tests\Feature\Orders;

use App\Models\User;
use App\Modules\Orders\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

final class ListOrdersTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_only_lists_their_own_orders(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $mine = Order::query()->create([
            'user_id' => $owner->id, 'description' => 'Mine', 'quantity' => 1,
        ]);
        Order::query()->create([
            'user_id' => $other->id, 'description' => 'Other', 'quantity' => 1,
        ]);

        Sanctum::actingAs($owner);

        $this->getJson('/api/v1/orders')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $mine->id);
    }
}
```

## 12. `tests/Feature/Orders/CreateOrderTest.php`

```php
<?php

namespace Tests\Feature\Orders;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

final class CreateOrderTest extends TestCase
{
    use RefreshDatabase;

    public function test_authenticated_user_can_create_an_order(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $this->postJson('/api/v1/orders', [
            'description' => 'Two blue notebooks',
            'quantity' => 2,
        ])->assertCreated()->assertJsonPath('data.quantity', 2);

        $this->assertDatabaseHas('orders', ['user_id' => $user->id, 'quantity' => 2]);
    }

    public function test_guest_cannot_create_an_order(): void
    {
        $this->postJson('/api/v1/orders', [
            'description' => 'Two blue notebooks',
            'quantity' => 2,
        ])->assertUnauthorized();
    }

    public function test_quantity_is_validated(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->postJson('/api/v1/orders', [
            'description' => 'Two blue notebooks',
            'quantity' => 0,
        ])->assertUnprocessable()->assertJsonValidationErrors(['quantity']);
    }
}
```

## 13. `tests/Feature/Orders/ShowOrderAuthorizationTest.php`

```php
<?php

namespace Tests\Feature\Orders;

use App\Models\User;
use App\Modules\Orders\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

final class ShowOrderAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_cannot_view_another_users_order(): void
    {
        $owner = User::factory()->create();
        $viewer = User::factory()->create();
        $order = Order::query()->create([
            'user_id' => $owner->id,
            'description' => 'Notebook',
            'quantity' => 1,
        ]);

        Sanctum::actingAs($viewer);

        $this->getJson("/api/v1/orders/{$order->id}")->assertForbidden();
    }
}
```

## Verificación

```bash
php artisan route:list --path=api
php artisan test --filter=CreateOrderTest
php artisan test --filter=ListOrdersTest
php artisan test --filter=ShowOrderAuthorizationTest
./vendor/bin/pint --test
```

**No se han ejecutado estos tests aquí**, porque este paquete es una guía Markdown, no una app Laravel instalada. Ajustar el ejemplo antes de copiarlo a un proyecto real.
