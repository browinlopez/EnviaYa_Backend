# =========================
# Stage 1: Build frontend assets
# =========================
FROM node:20-alpine AS node-builder
WORKDIR /app

# Copiar y instalar dependencias de Node
COPY package*.json ./
RUN npm ci

# Copiar el código y generar assets
COPY . .
RUN npm run build

# =========================
# Stage 2: PHP base con extensiones
# =========================
# Usamos FPM para mejor compatibilidad con Swoole y extensiones PCNTL/POSIX
FROM php:8.2-fpm-bullseye AS php-base

# Instalar extensiones necesarias y Swoole
RUN apt-get update && apt-get install -y \
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
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install pdo pdo_mysql zip gd mbstring pcntl posix \
    && pecl channel-update pecl.php.net \
    && pecl install swoole \
    && docker-php-ext-enable swoole \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /var/www

# =========================
# Stage 3: Instalar dependencias Composer
# =========================
FROM php-base AS composer-builder

COPY composer.json composer.lock ./

# Instalar Composer
RUN curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer

# Instalar dependencias de Laravel
RUN composer install --no-dev --optimize-autoloader --no-interaction --no-scripts

# Copiar el resto del código y optimizar autoload
COPY . .
RUN composer dump-autoload --optimize

# =========================
# Stage 4: Imagen final de producción
# =========================
FROM php-base

WORKDIR /var/www

# Configurar directorio como seguro para Git (evita 'dubious ownership')
RUN git config --global --add safe.directory /var/www

# Copiar código, vendor y assets frontend
COPY --chown=www-data:www-data . .
COPY --from=composer-builder --chown=www-data:www-data /var/www/vendor ./vendor
COPY --from=node-builder --chown=www-data:www-data /app/public/build ./public/build

# Configurar permisos
RUN mkdir -p storage/framework/{sessions,views,cache} \
    && mkdir -p bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache \
    && chmod -R 775 storage bootstrap/cache

# Octane ya viene instalado: es una dependencia declarada en composer.json, así
# que el stage 3 lo resuelve desde composer.lock junto al resto y config/octane.php
# está versionado en el repositorio.
#
# Antes se hacía aquí un `composer require laravel/octane --with-all-dependencies`,
# lo cual traía cuatro problemas: el build dejaba de ser reproducible (resolvía
# contra Packagist en vivo, no contra el lock), `--with-all-dependencies` podía
# subir otros paquetes solo en producción, al no llevar `--no-dev` metía las
# dependencias de desarrollo en la imagen final, y obligaba a instalar Composer
# aquí para poder ejecutarlo.

# Cache de configuraciones y rutas
RUN php artisan config:cache \
    && php artisan route:cache \
    && php artisan view:cache

# Puerto HTTP expuesto (Traefik se comunica aquí)
EXPOSE 8000

# Iniciar servidor Octane con Swoole
CMD ["php", "artisan", "octane:start", "--server=swoole", "--host=0.0.0.0", "--port=8000", "--workers=auto"]
