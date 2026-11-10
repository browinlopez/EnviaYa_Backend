# 18 — Checklists de entrega y code review

## Antes de implementar

- [ ] Identifiqué la ruta real, su módulo y el flujo actual.
- [ ] Revisé patrones de Laravel y convenciones ya presentes.
- [ ] Identifiqué tests relacionados y riesgos de autorización/contrato.
- [ ] Elegí los archivos mínimos sin refactorizar fuera de alcance.

## Durante la implementación

- [ ] Controller delgado y acción bien nombrada, sin ceremonias innecesarias.
- [ ] Validación y permisos del lado del servidor.
- [ ] Datos obtenidos por ámbitos seguros (usuario/tenant).
- [ ] Resource explícito; sin campos sensibles ni N+1 accidentales.
- [ ] Archivos breves y métodos cohesivos; no code golf.
- [ ] Cambios en DB / jobs / webhooks transaccionales o idempotentes cuando aplica.

## Antes de abrir PR

- [ ] Test exitoso agregado/actualizado.
- [ ] Casos negativos y cross-tenant cubiertos si aplica.
- [ ] `./vendor/bin/pint --test` ejecutado o bloqueo explicado.
- [ ] `php artisan test --filter=...` ejecutado o bloqueo explicado.
- [ ] `php artisan route:list --path=api` comprobado si cambié rutas.
- [ ] Revisé `git diff` para detectar cambios incidentales.
- [ ] Describí contrato, despliegue, migraciones y rollback si corresponde.

## Revisión de pares

- [ ] La responsabilidad es fácil de localizar.
- [ ] Ninguna abstracción nueva solo agrega indirección.
- [ ] No hay regresión de permisos, validación, paginación o respuesta.
- [ ] El tamaño por archivo es razonable para su cohesión.
- [ ] La solución sigue la documentación Laravel 12 y convenciones del repo.
