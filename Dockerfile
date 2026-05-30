# ==========================================================
# STAGE 1 - Build Frontend (Vite)
# ==========================================================
FROM node:20-alpine AS node-builder

WORKDIR /app

COPY package*.json ./

RUN npm ci

COPY . .

RUN npm run build


# ==========================================================
# STAGE 2 - PHP Base (CLI para Octane/Reverb)
# ==========================================================
FROM php:8.2-cli-bullseye AS php-base

# Dependencias del sistema
RUN apt-get update && apt-get install -y \
    git \
    curl \
    unzip \
    wget \
    zip \
    supervisor \
    libzip-dev \
    libpng-dev \
    libjpeg62-turbo-dev \
    libfreetype6-dev \
    libicu-dev \
    libonig-dev \
    libpq-dev \
    libssl-dev \
    autoconf \
    build-essential \
    pkg-config \
    && rm -rf /var/lib/apt/lists/*

# Configurar GD
RUN docker-php-ext-configure gd \
    --with-freetype \
    --with-jpeg

# Extensiones PHP necesarias para tu stack
RUN docker-php-ext-install -j$(nproc) \
    pdo \
    pdo_pgsql \
    pgsql \
    mbstring \
    bcmath \
    exif \
    intl \
    pcntl \
    posix \
    sockets \
    zip \
    gd

# Redis + Swoole
RUN pecl channel-update pecl.php.net \
    && pecl install redis swoole \
    && docker-php-ext-enable redis swoole

# Composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www


# ==========================================================
# STAGE 3 - Composer Dependencies
# ==========================================================
FROM php-base AS composer-builder

COPY composer.json composer.lock ./

RUN composer install \
    --no-dev \
    --prefer-dist \
    --optimize-autoloader \
    --no-interaction \
    --no-scripts

COPY . .

RUN composer dump-autoload --optimize


# ==========================================================
# STAGE 4 - Production Image
# ==========================================================
FROM php-base AS production

WORKDIR /var/www

# Git safe directory
RUN git config --global --add safe.directory /var/www

# Copiar proyecto
COPY --chown=www-data:www-data . .

# Copiar vendor
COPY --from=composer-builder \
    --chown=www-data:www-data \
    /var/www/vendor ./vendor

# Copiar assets frontend
COPY --from=node-builder \
    --chown=www-data:www-data \
    /app/public/build ./public/build

# Crear directorios Laravel
RUN mkdir -p \
    storage/framework/cache \
    storage/framework/sessions \
    storage/framework/views \
    storage/logs \
    bootstrap/cache

# Permisos
RUN chown -R www-data:www-data \
    storage \
    bootstrap/cache \
    && chmod -R 775 \
    storage \
    bootstrap/cache

# Optimización segura
RUN php artisan optimize:clear || true

EXPOSE 8000
EXPOSE 8080

USER www-data
