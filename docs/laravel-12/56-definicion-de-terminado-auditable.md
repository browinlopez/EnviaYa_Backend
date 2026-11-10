# 56 — Definition of Done de cada módulo backend

## Lista corta de aceptación

- [ ] Ubicación exacta de clases y responsabilidades y cambio mínimo documentado.
- [ ] Controller delgado; Action para caso de uso si lo amerita; Query para lectura no trivial.
- [ ] Validación, policy por registro, tenant scope y prueba de denegación.
- [ ] Contrato HTTP y código de errores compatibles con consumidores.
- [ ] Relaciones cargadas intencionalmente; SQL parametrizado; paginación/índice y benchmark si es hot path.
- [ ] Migraciones/constraints/transacciones según invariantes; idempotencia si hay reintentos.
- [ ] Colas/webhooks/Reverb seguros y consistentes si existen efectos asíncronos.
- [ ] Pruebas positivas, negativas, regresión y casos de concurrencia relevantes.
- [ ] Pint, Larastan, Composer y CI en verde; excepciones documentadas.
- [ ] Telemetría suficiente para detectar degradación; plan de rollback/mitigación si cambia producción.

**No bloquear un cambio mínimo por controles que no apliquen**. El reviewer decide aplicabilidad con razón explícita. Ningún checklist reemplaza pruebas de integración ni seguridad del entorno real.
