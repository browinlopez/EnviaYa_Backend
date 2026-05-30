# ==========================================================
# FRONTEND (VITE)
# ==========================================================
FROM node:20-alpine AS node-builder

WORKDIR /app

COPY package*.json ./
RUN npm ci

COPY . .
RUN npm run build


# ==========================================================
# PHP BASE
# ==========================================================
FROM php:8.2-cli-bullseye AS php-base

RUN apt-get update && apt-get install -y \
    git curl unzip zip \
    libzip-dev libpng-dev libjpeg62-turbo-dev libfreetype6-dev \
    libicu-dev libonig-dev libpq-dev \
    autoconf build-essential pkg-config \
    && rm -rf /var/lib/apt/lists/*

RUN docker-php-ext-configure gd --with-freetype --with-jpeg

RUN docker-php-ext-install -j$(nproc) \
    pdo pdo_pgsql pgsql \
    mbstring bcmath exif intl \
    pcntl posix sockets zip gd

# Redis + Swoole
RUN pecl install redis swoole \
    && docker-php-ext-enable redis swoole

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www


# ==========================================================
# COMPOSER BUILD (CORRECTO)
# ==========================================================
FROM php-base AS composer-builder

WORKDIR /var/www

COPY . .

# 🔥 IMPORTANTE: aquí se instalan TODAS las dependencias
RUN composer install \
    --no-dev \
    --prefer-dist \
    --optimize-autoloader \
    --no-interaction


# ==========================================================
# PRODUCTION IMAGE
# ==========================================================
FROM php-base AS production

WORKDIR /var/www

COPY --from=composer-builder /var/www /var/www
COPY --from=node-builder /app/public/build /var/www/public/build

# Laravel folders
RUN mkdir -p \
    storage/framework/cache \
    storage/framework/sessions \
    storage/framework/views \
    storage/logs \
    bootstrap/cache

RUN chown -R www-data:www-data /var/www \
    && chmod -R 775 storage bootstrap/cache

EXPOSE 8000 8080

USER www-data

# Default process (Octane)
CMD ["php", "artisan", "octane:start", "--server=swoole", "--host=0.0.0.0", "--port=8000", "--workers=auto", "--task-workers=auto"]