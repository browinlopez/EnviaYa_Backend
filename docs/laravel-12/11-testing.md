# 11 — Estrategia de pruebas por módulo

## Regla

Una modificación funcional trae una prueba que describe el resultado observable, no solo que un método se invocó. Priorizar **Feature Tests** HTTP para APIs Laravel; añadir Unit Tests para reglas puras y pruebas específicas para integraciones.

## Organización

```text
tests/
├── Feature/
│   ├── Orders/CreateOrderTest.php
│   ├── Orders/ListOrdersTest.php
│   └── Orders/ShowOrderAuthorizationTest.php
└── Unit/
    └── Orders/...
```

## Mínimo por caso de uso

1. Camino exitoso y código HTTP esperado.
2. Validación (entrada faltante, límites, tipos).
3. No autenticado / no autorizado y ownership cruzado entre usuarios o tenants.
4. Estado inválido, duplicados, transacción / integridad según riesgo.
5. Contrato JSON: claves, paginación, ausencia de datos sensibles.

## Herramientas nativas

- `RefreshDatabase` para tests DB; factories para datos.
- `postJson`, `getJson`, `assertJsonPath`, `assertDatabaseHas`.
- `Sanctum::actingAs($user)` para autenticación de API cuando se use Sanctum.
- `Http::fake()` para servicios externos; `Queue::fake()` donde se quiera probar el despacho.
- No mockear Eloquent por reflejo: una prueba de feature puede usar DB real de test.

## Tests de performance y tiempo real

- Evitar N+1: crear registros relacionados de distintos tamaños y observar que la cantidad de consultas **no crezca linealmente** con el número de filas devueltas.
- Verificar `simplePaginate` / `cursorPaginate` / `paginate` según contrato; `per_page` máximo y orden estable.
- Comprobar consulta cross-tenant vacía **antes** de serializar datos; probar sort e inputs maliciosos.
- Test de autorización de canal Reverb: propietario, usuario mismo tenant sin permiso, usuario otro tenant y anónimo.
- Test de `Event::fake()` / `Broadcast::fake()` según intención: que la Action despache después del cambio y que no lo haga si hay rollback; **un fake no comprueba entrega real ni latencia**.
- Test de benchmark separado: registrar SQL `count`, tiempo, percentiles y memoria; SQLite no reemplaza el plan MySQL/PostgreSQL.
- Ver `25-observabilidad-benchmark-consultas.md` y `29-checklist-pr-rendimiento.md`.

## Comandos

```bash
php artisan test --filter=CreateOrderTest
php artisan test --testsuite=Feature
php artisan test
```

Configurar `.env.testing` o `phpunit.xml` con DB de test **aislada**; jamás apuntar a producción. En pruebas paralelas revisar la configuración de DB y recursos compartidos.

**Regla IA:** no indicar «pasó la suite» si no se ejecutó. Si el entorno bloquea la prueba, decir exactamente por qué.
