# Infrastructure Guide — EnviaYa Backend

Guía de referencia completa de toda la infraestructura de servicios que compone el backend de **EnviaYa**. Cualquier ingeniero que quiera levantar, escalar o depurar el sistema debe leer este documento antes de tocar código.

---

## Diagrama de Arquitectura

```
┌─────────────────────────────────────────────────────────┐
│                    INTERNET / FRONTEND                  │
│               React Native App (iOS / Android)          │
└────────────────────────────┬────────────────────────────┘
                             │ HTTPS + WSS
┌────────────────────────────▼────────────────────────────┐
│                    NGINX (Reverse Proxy)                 │
│             /api/* → PHP-FPM (Laravel)                  │
│             /app  → Reverb (WebSockets)                 │
└──────┬────────────────┬────────────────┬────────────────┘
       │                │                │
┌──────▼──────┐  ┌──────▼──────┐  ┌──────▼──────┐
│   Laravel   │  │   Reverb    │  │    Redis    │
│  (API Core) │  │  (WS Server)│  │(Cache/Sess) │
└──────┬──────┘  └─────────────┘  └─────────────┘
       │
┌──────▼──────────────────────────────────────────────┐
│                    RabbitMQ                          │
│  Colas: default | notifications | orders | gps_tracking │
└──────┬──────────────────────────────────────────────┘
       │ Workers (Supervisor)
┌──────▼──────┐
│  PostgreSQL │
│  + PostGIS  │
└─────────────┘
```

---

## 1. PostgreSQL + PostGIS

### Descripción
Base de datos principal del sistema. Se usa **PostGIS** para almacenar y consultar coordenadas geográficas de pedidos y domiciliarios.

### Variables de Entorno
```env
DB_CONNECTION=pgsql
DB_HOST=127.0.0.1
DB_PORT=5432
DB_DATABASE=db_enviaya
DB_USERNAME=postgres
DB_PASSWORD=secret
```

### Extensiones Requeridas
```sql
CREATE EXTENSION IF NOT EXISTS postgis;
CREATE EXTENSION IF NOT EXISTS postgis_topology;
```

### Tablas con Datos Geoespaciales
| Tabla | Columna | Tipo |
|---|---|---|
| `orders_sales` | `pickup_location` | `geometry(Point, 4326)` |
| `orders_sales` | `dropoff_location` | `geometry(Point, 4326)` |
| `domiciliaries` | `last_location` | `geometry(Point, 4326)` |
| `order_geolocations` | `location` | `geometry(Point, 4326)` |

### Consultas de Ejemplo (PostGIS)
```sql
-- Buscar domiciliarios en radio de 3km desde un pedido
SELECT id, ST_Distance(last_location::geography, ST_MakePoint(-74.076, 4.60)::geography) as dist_meters
FROM domiciliaries
WHERE available = true
  AND ST_DWithin(last_location::geography, ST_MakePoint(-74.076, 4.60)::geography, 3000)
ORDER BY dist_meters ASC;
```

---

## 2. Redis

### Descripción
Utilizado para **cache de aplicación** y **gestión de sesiones** en producción. No se usa actualmente como driver de colas (eso lo hace RabbitMQ).

### Variables de Entorno
```env
REDIS_CLIENT=phpredis
REDIS_HOST=127.0.0.1
REDIS_PASSWORD=null
REDIS_PORT=6379

SESSION_DRIVER=redis
CACHE_STORE=redis
```

### Requisito del Servidor
- Extensión PHP `phpredis` instalada (o usar `predis` si no está disponible).
- Redis Server >= 6.0.

---

## 3. RabbitMQ

### Descripción
Sistema de colas de mensajería para **todos los procesos asíncronos** del sistema. Reemplaza el driver `database` para mayor confiabilidad y escalabilidad en producción.

### Variables de Entorno
```env
QUEUE_CONNECTION=rabbitmq

RABBITMQ_HOST=127.0.0.1
RABBITMQ_PORT=5672
RABBITMQ_USER=guest
RABBITMQ_PASSWORD=guest
RABBITMQ_VHOST=/

RABBITMQ_QUEUE=default
RABBITMQ_HEARTBEAT=0
RABBITMQ_CONNECTION_TIMEOUT=3
RABBITMQ_SSL=false
```

### Colas y sus Responsabilidades
| Cola | Descripción | Jobs/Clases |
|---|---|---|
| `default` | Tareas genéricas y de baja prioridad | Varios |
| `notifications` | Correos, push, SMS y notificaciones del sistema | `NotifyStoreJob`, `OrderStatusUpdated` |
| `orders` | Búsqueda de domiciliario, asignación y retry | `FindDriverJob` |
| `gps_tracking` | Persistencia de coordenadas GPS en alta frecuencia | `PersistLocationJob` |

### Workers con Supervisor (Producción)

Crear el archivo `/etc/supervisor/conf.d/enviaya-workers.conf`:

```ini
[program:enviaya-worker-notifications]
process_name=%(program_name)s_%(process_num)02d
command=php /var/www/html/artisan queue:work rabbitmq --queue=notifications --sleep=3 --tries=3 --timeout=30
autostart=true
autorestart=true
numprocs=2
user=www-data
redirect_stderr=true
stdout_logfile=/var/www/html/storage/logs/worker-notifications.log

[program:enviaya-worker-orders]
process_name=%(program_name)s_%(process_num)02d
command=php /var/www/html/artisan queue:work rabbitmq --queue=orders --sleep=3 --tries=5 --timeout=60
autostart=true
autorestart=true
numprocs=1
user=www-data
redirect_stderr=true
stdout_logfile=/var/www/html/storage/logs/worker-orders.log

[program:enviaya-worker-gps]
process_name=%(program_name)s_%(process_num)02d
command=php /var/www/html/artisan queue:work rabbitmq --queue=gps_tracking --sleep=1 --tries=3 --timeout=15
autostart=true
autorestart=true
numprocs=3
user=www-data
redirect_stderr=true
stdout_logfile=/var/www/html/storage/logs/worker-gps.log

[program:enviaya-worker-default]
process_name=%(program_name)s_%(process_num)02d
command=php /var/www/html/artisan queue:work rabbitmq --queue=default --sleep=3 --tries=3 --timeout=90
autostart=true
autorestart=true
numprocs=1
user=www-data
redirect_stderr=true
stdout_logfile=/var/www/html/storage/logs/worker-default.log
```

Comandos de gestión:
```bash
supervisorctl reread
supervisorctl update
supervisorctl start all
supervisorctl status
```

---

## 4. Laravel Reverb (WebSockets)

### Descripción
Servidor de WebSockets nativo para Laravel. Maneja comunicación en tiempo real para:
- Chat entre usuarios
- Actualizaciones de estado de pedidos
- Tracking GPS en tiempo real del domiciliario

### Variables de Entorno
```env
BROADCAST_CONNECTION=reverb

REVERB_APP_ID=your-app-id
REVERB_APP_KEY=your-app-key
REVERB_APP_SECRET=your-app-secret
REVERB_HOST=localhost
REVERB_PORT=8080
REVERB_SCHEME=http
```

### Canales Implementados
| Canal | Tipo | Evento |
|---|---|---|
| `private-chat.{chat_id}` | Private | `MessageSent` |
| `private-gps.order.{order_id}` | Private | `LocationUpdated` |
| `private-App.Models.User.{user_id}` | Private | `OrderStatusUpdated` (Notifications) |

### Iniciar en Desarrollo
```bash
php artisan reverb:start --debug
```

### Iniciar en Producción (con Supervisor)
```ini
[program:enviaya-reverb]
command=php /var/www/html/artisan reverb:start --host=0.0.0.0 --port=8080
autostart=true
autorestart=true
user=www-data
stdout_logfile=/var/www/html/storage/logs/reverb.log
```

---

## 5. Pasarela de Pagos (Bold / Wompi)

### Descripción
El sistema usa **Bold** como pasarela de pagos principal. Los webhooks de confirmación de pago se reciben en `/v1/webhooks/{gateway}`.

### Variables de Entorno
```env
PAYMENT_GATEWAY=bold
BOLD_API_KEY=your-bold-api-key
BOLD_API_SECRET=your-bold-api-secret
BOLD_WEBHOOK_SECRET=your-bold-webhook-secret
```

### Flujo de Pago
```
App → POST /v1/orders → Crea pedido → Genera Payment Intent
App → GET  /v1/payment-intent/{referenceId} → Obtiene estado
Bold → POST /v1/webhooks/bold → Confirma pago (firmado con HMAC)
App → GET  /v1/payment/status → Consulta estado actual
```

---

## 6. Almacenamiento (Filesystem)

### Descripción
Actualmente configurado para almacenamiento **local**. En producción se recomienda S3 o compatible (MinIO, Cloudflare R2).

### Variables de Entorno (Producción con S3)
```env
FILESYSTEM_DISK=s3
AWS_ACCESS_KEY_ID=your-key
AWS_SECRET_ACCESS_KEY=your-secret
AWS_DEFAULT_REGION=us-east-1
AWS_BUCKET=enviaya-storage
AWS_USE_PATH_STYLE_ENDPOINT=false
```

---

## 7. Correo Electrónico

### Variables de Entorno (Producción)
```env
MAIL_MAILER=smtp
MAIL_HOST=smtp.resend.com
MAIL_PORT=465
MAIL_USERNAME=resend
MAIL_PASSWORD=your-resend-api-key
MAIL_SCHEME=tls
MAIL_FROM_ADDRESS=noreply@enviaya.com
MAIL_FROM_NAME="EnviaYa"
```

---

## 8. Push Notifications (FCM)

### Descripción
Las notificaciones push a dispositivos móviles se envían a través de **Firebase Cloud Messaging (FCM)**.

### Variables de Entorno
```env
FCM_SERVER_KEY=your-fcm-server-key
FCM_SENDER_ID=your-sender-id
```

### Flujo
1. App React Native obtiene `device_token` del SDK de Firebase.
2. Lo registra llamando a `PUT /v1/users/update` con el campo `device_token`.
3. El backend almacena el token y lo usa en `NotifyStoreJob` para enviar la notificación.

---

## 9. Monitoreo de Jobs Fallidos

### Visualizar Jobs Fallidos
```bash
php artisan queue:failed
```

### Reintentar un Job Fallido
```bash
php artisan queue:retry {id}
# O todos a la vez:
php artisan queue:retry all
```

### Eliminar Jobs Fallidos
```bash
php artisan queue:flush
```

> [!WARNING]
> En producción, configura alertas (Slack/email) para cuando la tabla `failed_jobs` crezca inesperadamente. Esto indica un fallo sistémico en algún servicio externo.
