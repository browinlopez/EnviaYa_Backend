# 23 — Laravel Reverb: arquitectura WebSocket modular para API

## Objetivo y alcance

**Reverb** es el servidor WebSocket oficial compatible con broadcasting en Laravel 12. Permite comunicar cambios al frontend sin polling constante, pero **no sustituye HTTP ni la persistencia en DB**. Es una capa de notificación en tiempo real sobre los casos de uso.

### Flujo recomendado

```text
POST/PATCH API
    -> FormRequest + Policy
    -> Action (transacción + persistencia)
    -> Event de dominio / broadcasting (después del commit)
    -> Queue Worker (ShouldBroadcast, por defecto)
    -> Reverb (servidor WebSocket)
    -> Clientes suscritos a canales autorizados
    -> UI actualiza estado / solicita detalle HTTP si procede
```

El frontend siempre debe poder recuperar el estado correcto mediante HTTP tras reconexión, pérdida de eventos, actualización de página o login. Los eventos son señales, no una garantía de entrega duradera ni un reemplazo de sincronización.

## Carpeta sugerida dentro de cada feature

```text
app/Modules/Orders/
├── Actions/UpdateOrderStatus.php
├── Events/OrderStatusUpdated.php
├── Broadcasting/OrderChannel.php
├── Broadcasting/channels.php
├── Queries/FindVisibleOrder.php
├── Models/Order.php
├── Policies/OrderPolicy.php
└── Http/... 
routes/channels.php             # solo registra archivos de módulos
bootstrap/app.php              # configuración del broadcasting
config/broadcasting.php        # si está presente / instalado
config/reverb.php              # generado por instalación
```

No crear un `WebSocketService` universal, ni enviar broadcast directamente desde el controlador. El evento pertenece al módulo propietario del hecho, y la clase de canal define quién puede escucharlo.

## Instalación orientada a backend puro

```bash
php artisan install:broadcasting --reverb
php artisan channel:list
php artisan reverb:start
php artisan queue:work
```

`install:broadcasting --reverb` instala/configura broadcasting y Reverb en Laravel 12; puede instalar dependencias JavaScript para el cliente. En un proyecto **backend-only**, revisar el diff y mantener únicamente lo necesario del lado del servidor; no incorporar frontend ni reemplazar configuración existente a ciegas. Si el proyecto ya usa otro broadcaster, planear la migración y verificar compatibilidad.

### Variables de entorno de ejemplo (valores ficticios)

```dotenv
BROADCAST_CONNECTION=reverb
QUEUE_CONNECTION=redis
REVERB_APP_ID=example-app
REVERB_APP_KEY=example-public-key
REVERB_APP_SECRET=replace-with-secret
REVERB_SERVER_HOST=0.0.0.0
REVERB_SERVER_PORT=8080
REVERB_HOST=ws.example.com
REVERB_PORT=443
REVERB_SCHEME=https
```

- `REVERB_SERVER_HOST/PORT`: **donde escucha** Reverb, dentro de la infraestructura.
- `REVERB_HOST/PORT`: **destino usado por Laravel** para publicar eventos al servidor Reverb; debe resolverse y ser alcanzable desde la API.
- Cliente web/móvil: usa hostname, puerto y clave pública de aplicación, nunca el `REVERB_APP_SECRET`.
- `BROADCAST_CONNECTION` no es lo mismo que `QUEUE_CONNECTION`; enviar eventos en cola requiere un worker operativo.
- `REVERB_SCALING_ENABLED=true` solo al habilitar escalado con Redis y topología adecuada.
- Configurar `allowed_origins` de `config/reverb.php` explícitamente para clientes web; `*` no es estándar de producción. **CORS y allowed_origins son controles distintos**, no sustituyen autorización de canales.

## Qué emitir y qué no

**Sí:** cambio de estado de orden, disponibilidad, actualización de pedido, progreso de un proceso de larga duración, presencia de agentes cuando haya caso de uso.

**No:** cada lectura o consulta GET, payload completo de modelos con secretos, eventos de cada loop masivo, estados transitorios sin semántica, datos que ya se pueden obtener con una API normal y no requieren latencia baja.

**Granularidad:** emitir eventos de negocio («orden actualizada») y permitir que el cliente vuelva a consultar el recurso; si se incluye snapshot mínimo, versionarlo y definir consistencia.

## Contrato de evento

Cada evento debe especificar `nombre`, `canal`, `versión del payload`, `campos`, `quién puede suscribirse`, `emisor`, `orden relativo` y `plan de reconexión`. Si hay varios frontends, ese contrato es parte de la API y debe revisarse como cualquier respuesta JSON.

**Fuentes Laravel 12:** https://laravel.com/docs/12.x/reverb , https://laravel.com/docs/12.x/broadcasting , https://laravel.com/docs/12.x/queues .

## Implementación detallada

Para naming y autorización de canales, guard de `/broadcasting/auth`, `ShouldDispatchAfterCommit`, ejemplo de evento de payload mínimo y tests cross-tenant, continuar con `27-reverb-canales-seguridad-eventos.md`. Para performance del proceso y reconexión, `30-escalabilidad-concurrencia-produccion.md` y `31-ejemplo-modulo-consultas-reverb.md`.
