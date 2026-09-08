# =========================
# Etapa 1: assets del frontend
# =========================
# Las vistas Blade que quedan —verificación de correo, recuperar contraseña,
# el PDF del acuerdo— usan @vite, así que hay que compilarlas aunque el panel
# viva en otro proyecto.
FROM node:20-alpine AS node-builder
WORKDIR /app

COPY package*.json ./
RUN npm ci

COPY . .
RUN npm run build

# =========================
# Etapa 2: PHP con sus extensiones
# =========================
# Debian 12 (bookworm), NO bullseye.
#
# El 31 de agosto de 2026 Debian publicó el ÚLTIMO `Release` de
# `bullseye-security`, con validez de una semana. Al vencer, `apt-get update`
# empezó a responder «Release file … is expired» y el build dejó de compilar
# de un día para otro, sin que nadie tocara nada. No hay vuelta atrás: ese
# archivo no se va a volver a firmar nunca.
#
# Se puede silenciar con `Acquire::Check-Valid-Until=false`, y compila. Lo que
# no arregla es el motivo: sería seguir levantando la API sobre un sistema que
# ya no recibe parches de seguridad. Bookworm tiene soporte hasta 2028.
FROM php:8.2-fpm-bookworm AS php-base

RUN apt-get update && apt-get install -y --no-install-recommends \
    git \
    curl \
    libzip-dev \
    unzip \
    wget \
    libpng-dev \
    libjpeg-dev \
    libfreetype6-dev \
    libonig-dev \
    libssl-dev \
    autoconf \
    build-essential \
    pkg-config \
    # mysqldump: lo necesita spatie/laravel-backup. Sin el cliente de MySQL la
    # copia de seguridad diaria falla todas las noches y solo se descubre el
    # día que hay que restaurar.
    default-mysql-client \
    # procps: el pgrep con que el contenedor de la cola comprueba su salud.
    procps \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install pdo pdo_mysql zip gd mbstring pcntl posix \
    && pecl channel-update pecl.php.net \
    && pecl install swoole \
    && docker-php-ext-enable swoole \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /var/www

# =========================
# Etapa 3: dependencias de Composer
# =========================
FROM php-base AS composer-builder

COPY composer.json composer.lock ./

RUN curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer

RUN composer install --no-dev --optimize-autoloader --no-interaction --no-scripts

COPY . .
RUN composer dump-autoload --optimize

# =========================
# Etapa 4: imagen final
# =========================
FROM php-base

WORKDIR /var/www

RUN git config --global --add safe.directory /var/www

COPY --chown=www-data:www-data . .
COPY --from=composer-builder --chown=www-data:www-data /var/www/vendor ./vendor
COPY --from=node-builder --chown=www-data:www-data /app/public/build ./public/build

COPY docker/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

RUN mkdir -p storage/framework/{sessions,views,cache} \
    && mkdir -p bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache \
    && chmod -R 775 storage bootstrap/cache

# Octane viene de composer.lock, resuelto en la etapa 3 junto al resto: el
# build es reproducible y no consulta Packagist en vivo.
#
# NO se cachean configuración, rutas ni vistas aquí.
#
# `php artisan config:cache` congela el valor de cada env() dentro de un
# archivo. Durante el build las variables del servidor todavía no existen —las
# inyecta Dokploy al arrancar el contenedor— así que la caché se generaba con
# la configuración de desarrollo y en producción GANABA sobre el entorno real:
# el contenedor intentaba conectarse a 127.0.0.1 y a la base local. Ahora lo
# hace el entrypoint, ya con el entorno puesto.

EXPOSE 8000 8080

# Ese `api` es solo el valor por defecto: docker-compose se lo cambia a cada
# servicio (reverb, worker, scheduler).
ENTRYPOINT ["entrypoint.sh"]
CMD ["api"]
