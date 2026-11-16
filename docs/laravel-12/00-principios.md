# 00 — Principios y prioridades

## Prioridades en orden

1. Correctitud, privacidad, seguridad y compatibilidad del contrato público.
2. Facilidad para encontrar la responsabilidad que debe cambiar.
3. Cohesión de archivos, bajo acoplamiento entre módulos y pruebas claras.
4. Legibilidad y consistencia con Laravel 12.
5. Tamaño reducido de cada archivo.

La regla **no es «menos líneas a cualquier costo»**: es que cada archivo haga pocas cosas y que cambiar una regla de negocio no obligue a editar todo el proyecto.

## Decisiones de diseño

- Un módulo corresponde a un área funcional o contexto de negocio; no a una tabla.
- Una `Action` representa una operación concreta (`CreateOrder`, `CancelOrder`). Evita `OrderService` con 25 métodos.
- `Query` se agrega solo cuando filtros, joins, scopes o paginación se vuelven relevantes.
- `DTO` / `Data` solo cuando el mismo esquema de datos atraviesa varias capas, integra proveedores o necesita invariantes. Para CRUD simple, `$request->validated()` basta.
- Inyecta clases concretas autodescubribles; crea una interfaz cuando hay implementaciones alternativas, aislamiento de infraestructura o contrato compartido tangible.
- Una abstracción debe reducir complejidad total y no simplemente distribuir código entre cinco archivos.

## En una revisión

Preguntar: ¿puedo señalar en menos de un minuto el archivo que controla esta regla? ¿Tengo que recorrer más de tres capas para comprender una operación sencilla? ¿Este cambio toca otro módulo sin justificación? ¿Existe una prueba que lo cubra?

## Anti-patrones

- Carpetas por tipo técnico a nivel global (`Services/`, `Repositories/`, `Controllers/`) para cientos de funcionalidades.
- Servicios genéricos con lógica, SQL, validación, permisos y JSON mezclados.
- «Clean Architecture» impuesta con wrappers sobre todos los métodos de Eloquent.
- Acortar nombres para ahorrar caracteres; crear 12 clases triviales para guardar un registro.
- Copiar el mismo helper en cada módulo por evitar una dependencia explícita.
