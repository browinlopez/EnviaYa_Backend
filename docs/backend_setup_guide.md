# Backend Setup Guide — EnviaYa

Guía completa para instalar y ejecutar el backend de **EnviaYa** en entornos locales y de producción.

---

## Prerequisitos

| Herramienta | Versión Mínima |
|---|---|
| PHP | 8.2+ |
| Composer | 2.x |
| PostgreSQL | 14+ |
| PostGIS | 3.x |
| Redis | 6.x |
| RabbitMQ | 3.11+ |
| Node.js | 18+ (para assets y Reverb) |

---

## Instalación Local

### 1. Clonar el Repositorio
```bash
git clone https://github.com/tu-org/enviaya-backend.git
cd enviaya-backend
```

### 2. Instalar Dependencias PHP
```bash
composer install
```

### 3. Configurar el Entorno
```bash
cp .env.example .env
php artisan key:generate
```

Editar el archivo `.env` con tus valores locales (ver `.env.example` para referencia completa).

### 4. Configurar PostgreSQL con PostGIS

**Opción A — Instalación manual:**
```bash
# Ubuntu/Debian
sudo apt install postgresql-16 postgresql-16-postgis-3

# MacOS (Homebrew)
brew install postgresql postgis

# Windows (Laragon)
# PostGIS viene incluido en Laragon con PostgreSQL
```

**Crear la base de datos:**
```bash
psql -U postgres -c "CREATE DATABASE db_enviaya;"
psql -U postgres -d db_enviaya -c "CREATE EXTENSION IF NOT EXISTS postgis;"
psql -U postgres -d db_enviaya -c "CREATE EXTENSION IF NOT EXISTS postgis_topology;"
```

### 5. Ejecutar Migraciones y Seeders
```bash
php artisan migrate
php artisan db:seed
```

### 6. Instalar y Configurar RabbitMQ (Local)

**Ubuntu/Debian:**
```bash
sudo apt install rabbitmq-server
sudo systemctl enable rabbitmq-server
sudo systemctl start rabbitmq-server

# Habilitar panel de administración web (http://localhost:15672)
sudo rabbitmq-plugins enable rabbitmq_management
```

**MacOS (Homebrew):**
```bash
brew install rabbitmq
brew services start rabbitmq
```

**Windows (Laragon / Instalador):**
Descarga RabbitMQ desde https://www.rabbitmq.com/install-windows.html.

Credenciales por defecto: `guest` / `guest`.

### 7. Instalar Redis (Local)

**Ubuntu/Debian:**
```bash
sudo apt install redis-server
sudo systemctl enable redis-server
```

**MacOS:**
```bash
brew install redis
brew services start redis
```

**Windows:**
Descargar desde https://github.com/microsoftarchive/redis/releases o usar WSL.

### 8. Iniciar todos los Servicios en Desarrollo

El siguiente comando inicia el servidor Laravel, el worker de colas, Reverb y los logs simultáneamente:

```bash
composer run dev
```

> Esto ejecuta `php artisan serve`, `php artisan queue:listen rabbitmq`, `php artisan reverb:start`, y `php artisan pail`.

**Para ejecutar cada servicio por separado:**
```bash
# API
php artisan serve

# Worker RabbitMQ (todas las colas)
php artisan queue:work rabbitmq --queue=notifications,orders,gps_tracking,default

# WebSockets Reverb
php artisan reverb:start --debug

# Monitoreo de logs
php artisan pail
```

### 9. Verificar la Instalación

```bash
# Verificar rutas
php artisan route:list

# Verificar colas
php artisan queue:monitor rabbitmq:default,notifications,orders,gps_tracking

# Verificar conexión a BD
php artisan tinker
>>> DB::connection()->getPdo()
```

---

## Docker Compose (Opción Recomendada para Desarrollo)

Crear `docker-compose.yml` en la raíz del proyecto:

```yaml
version: '3.9'
services:
  app:
    build: .
    ports:
      - "8000:8000"
      - "8080:8080"
    volumes:
      - .:/var/www/html
    depends_on:
      - postgres
      - redis
      - rabbitmq
    environment:
      - DB_HOST=postgres
      - REDIS_HOST=redis
      - RABBITMQ_HOST=rabbitmq

  postgres:
    image: postgis/postgis:16-3.4
    environment:
      POSTGRES_DB: db_enviaya
      POSTGRES_USER: postgres
      POSTGRES_PASSWORD: secret
    ports:
      - "5432:5432"
    volumes:
      - postgres_data:/var/lib/postgresql/data

  redis:
    image: redis:7-alpine
    ports:
      - "6379:6379"

  rabbitmq:
    image: rabbitmq:3-management
    ports:
      - "5672:5672"
      - "15672:15672"
    environment:
      RABBITMQ_DEFAULT_USER: guest
      RABBITMQ_DEFAULT_PASS: guest

volumes:
  postgres_data:
```

Iniciar:
```bash
docker compose up -d
php artisan migrate
php artisan db:seed
```

---

## Instalación en Producción

### Checklist Pre-Deploy

- [ ] Variables de entorno configuradas en el servidor (NO en el repositorio)
- [ ] `APP_ENV=production` y `APP_DEBUG=false`
- [ ] `APP_KEY` generado con `php artisan key:generate`
- [ ] PostgreSQL con PostGIS habilitado
- [ ] Redis corriendo y accesible
- [ ] RabbitMQ corriendo con usuario dedicado (NO usar `guest` en producción)
- [ ] Supervisor instalado y configurado para workers
- [ ] NGINX configurado como reverse proxy
- [ ] SSL/TLS habilitado (`APP_URL=https://...`)

### Pasos de Deploy

```bash
# 1. Clonar/actualizar código
git pull origin main

# 2. Instalar dependencias (sin dev)
composer install --no-dev --optimize-autoloader

# 3. Configurar entorno
php artisan config:cache
php artisan route:cache
php artisan event:cache

# 4. Ejecutar migraciones
php artisan migrate --force

# 5. Reiniciar workers
supervisorctl restart all

# 6. Reiniciar Reverb
supervisorctl restart enviaya-reverb
```

### Usuario RabbitMQ para Producción

```bash
# En el servidor de RabbitMQ
rabbitmqctl add_user enviaya_user SecurePassword123
rabbitmqctl set_user_tags enviaya_user administrator
rabbitmqctl set_permissions -p / enviaya_user ".*" ".*" ".*"
```

Actualizar `.env`:
```env
RABBITMQ_USER=enviaya_user
RABBITMQ_PASSWORD=SecurePassword123
```

### NGINX Configuration
```nginx
server {
    listen 80;
    server_name api.enviaya.com;

    root /var/www/html/public;
    index index.php;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location /app {
        proxy_http_version 1.1;
        proxy_set_header Upgrade $http_upgrade;
        proxy_set_header Connection "Upgrade";
        proxy_set_header Host $host;
        proxy_pass http://127.0.0.1:8080;
    }

    location ~ \.php$ {
        fastcgi_pass unix:/var/run/php/php8.3-fpm.sock;
        fastcgi_index index.php;
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
    }
}
```

### Escalabilidad

Para escalar horizontalmente, todos los workers pueden correr en múltiples servidores siempre que compartan:
- La misma instancia de PostgreSQL
- La misma instancia de Redis
- La misma instancia de RabbitMQ

Reverb puede escalarse con un balanceador de carga WebSocket (e.g. HAProxy) o utilizando Laravel Pulse/Horizon.

---

## Variables de Entorno en Producción (Resumen)

```env
APP_NAME="EnviaYa"
APP_ENV=production
APP_DEBUG=false
APP_URL=https://api.enviaya.com

DB_CONNECTION=pgsql
DB_HOST=tu-host-postgres
DB_PORT=5432
DB_DATABASE=db_enviaya
DB_USERNAME=enviaya_db_user
DB_PASSWORD=tu-password-seguro

SESSION_DRIVER=redis
CACHE_STORE=redis
REDIS_HOST=tu-host-redis
REDIS_PASSWORD=tu-redis-password
REDIS_PORT=6379

QUEUE_CONNECTION=rabbitmq
RABBITMQ_HOST=tu-host-rabbitmq
RABBITMQ_PORT=5672
RABBITMQ_USER=enviaya_user
RABBITMQ_PASSWORD=tu-rabbit-password
RABBITMQ_VHOST=/

BROADCAST_CONNECTION=reverb
REVERB_APP_ID=enviaya-prod
REVERB_APP_KEY=tu-reverb-key
REVERB_APP_SECRET=tu-reverb-secret
REVERB_HOST=api.enviaya.com
REVERB_PORT=8080
REVERB_SCHEME=https

MAIL_MAILER=smtp
MAIL_HOST=smtp.resend.com
MAIL_PORT=465
MAIL_USERNAME=resend
MAIL_PASSWORD=tu-resend-key
MAIL_FROM_ADDRESS=noreply@enviaya.com
MAIL_FROM_NAME="EnviaYa"

BOLD_API_KEY=tu-bold-api-key
BOLD_API_SECRET=tu-bold-api-secret
BOLD_WEBHOOK_SECRET=tu-bold-webhook-secret

FCM_SERVER_KEY=tu-fcm-server-key
FCM_SENDER_ID=tu-sender-id
```
