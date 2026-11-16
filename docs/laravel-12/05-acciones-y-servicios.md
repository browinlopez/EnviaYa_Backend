# 05 — Actions, servicios y dependencias

## La unidad de negocio es el caso de uso

Nombres que describen una operación: `CreateOrder`, `CancelOrder`, `ApproveInvoice`, `AssignDriver`.

```php
final class CancelOrder
{
    public function handle(Order $order): void
    {
        if ($order->status !== 'pending') {
            throw new CannotCancelOrder();
        }

        $order->update(['status' => 'cancelled']);
    }
}
```

*Fragmento conceptual; requiere las clases y atributos propios del proyecto.*

## Cuándo crear un Service

- Cuando encapsula una **capacidad técnica o de integración** reutilizable: `PaymentGateway`, `DocumentStorage`, `TaxCalculator`.
- Cuando existe comportamiento estable usado por varias Actions.
- No crear `OrderService` solo porque el nombre suena arquitectónico.

## Interfaces / contracts

- Usarlas si existe proveedor intercambiable, varias implementaciones, un boundary externo o una necesidad concreta de aislar el test.
- Para clases concretas simples, el contenedor de Laravel resuelve por inyección sin bindings manuales.
- Registrar implementaciones de interfaces en un Service Provider mediante `register()`; no colocar trabajo de arranque en `register()`.

## Dependencias entre módulos

- Preferir que `Orders` orqueste su operación y llame una interfaz estable de `Billing` si hay dependencia explícita.
- Para reacciones secundarias, eventos + listeners / jobs cuando se justifique.
- Evitar dependencias circulares (`Orders → Billing → Orders`).

## Contraejemplos

- `new Service()` en controllers si el contenedor puede inyectarlo.
- Traits usados para mezclar 10 acciones.
- Interfaces `IOrderService` con única implementación sin necesidad.
- Actions que reciben `Request` HTTP (el negocio debe recibir datos y entidades, no transporte HTTP).
