# 36 — Integraciones HTTP, webhooks y tolerancia a fallos

**Estado:** alta prioridad si hay proveedores externos, pasarelas de pago o callbacks.

## Ubicación

```text
app/Modules/Payments/
  Integrations/GatewayClient.php       # protocolo HTTP del proveedor
  Actions/ConfirmGatewayPayment.php    # regla de negocio local
  Http/Controllers/GatewayWebhookController.php
  Http/Requests/                      # entrada estándar si aplica
  Jobs/ProcessGatewayWebhook.php
```

**GatewayClient** no debe conocer controladores, policies ni modelos de otros módulos. La Action no debe conocer detalles de headers del proveedor.

## Reglas HTTP saliente

- `Http::connectTimeout(...)` y `timeout(...)` explícitos por integración, según necesidades reales.
- Reintentos solo en operaciones seguras o con garantía de idempotencia del proveedor; *POST no idempotente no se reintenta ciegamente*.
- Limitar reintentos, usar backoff/jitter cuando corresponda y respetar `429`/`Retry-After` si el contrato lo permite.
- Validar estructura/tipos del JSON recibido; tratar respuestas 2xx semánticamente inválidas como error.
- Registrar código HTTP, latencia y código de proveedor, no tokens ni payloads sensibles.
- Conservar timeouts totales menores al presupuesto de latencia del endpoint o mover el proceso a cola.
- Fallar cerrado cuando una comprobación crítica de seguridad/importe no esté disponible.

## Webhook entrante

1. Leer **cuerpo bruto** y verificar firma con secreto del proveedor.
2. Verificar timestamp/ventana de frescura cuando el protocolo lo incluya.
3. Persistir ID único de evento o hash canónico para deduplicación con constraint `UNIQUE`.
4. Confirmar recepción solo según garantías deseadas: no reconocer exitosamente algo que no se persistió.
5. Procesar con Job seguro ante reintentos; reconciliar estado del pago en DB.
6. Diseñar el estado de negocio y qué sucede si evento llega fuera de orden.
7. Proteger endpoint de llamadas directas de usuarios sin verificar firma.

## Pruebas

Usar `Http::fake()` para latencia/fallos HTTP simulables; integrar con sandbox real del proveedor en pruebas controladas. Casos: firma inválida, duplicado, fuera de orden, timeout, 429 y retry.

**Fuentes:** https://laravel.com/docs/12.x/http-client · https://laravel.com/docs/12.x/queues · https://api-security.owasp.org/editions/2023/en/0x11-t10/
