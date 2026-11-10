# 20 — Adopción progresiva y excepciones razonables

## No hacer big-bang refactor

Para un backend existente:

1. Inventariar rutas, middleware, modelos, servicios y tests.
2. Elegir un módulo / endpoint que se vaya a modificar de verdad.
3. Aplicar el patrón nuevo solo donde reduzca deuda técnica.
4. Añadir cobertura antes de mover lógica sensible.
5. Mantener namespaces / importaciones existentes hasta que el cambio esté probado.
6. Registrar excepciones con contexto y fecha de revisión.

## Decisiones flexibles

- **Controller REST tradicional**: se permite si tiene hasta cinco/siete acciones muy cortas y es fácil navegarlo; invocable es preferencia, no obligación de Laravel.
- **Repository**: válido para sources alternos, query compleja reusable o límites reales de infraestructura; no para duplicar Eloquent sin valor.
- **DTO**: válido para invariantes o transformación compleja, pero no obligado en cada endpoint.
- **Service**: válido para capacidad compartida, no como carpeta genérica donde meter todo.
- **Módulo muy pequeño**: puede empezar con Routes + Controller + Request + Resource; sin Action si no existe lógica de negocio que extraer.
- **Excepción de líneas**: permitida cuando separar empeora lectura, coste o testabilidad; dejar criterio en el PR.

## Anti-meta

No optimizar el número de carpetas ni el número de líneas como métrica de productividad. Optimizar: menor esfuerzo para entender un cambio, menor superficie de diff, menor riesgo de regresión y menor tiempo de revisión.

## Respuesta exigida a un asistente de código

```text
Cambio: [comportamiento]
Módulo responsable: [nombre]
Archivos modificados: [rutas reales]
Razón de cada modificación: [1 frase por archivo]
Contrato/DB/seguridad afectada: [sí/no + detalle]
Pruebas realmente ejecutadas: [comando + resultado]
Pendiente / riesgo: [si existe]
```
