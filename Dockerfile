# ==========================================================
# STAGE 1 - FRONTEND (VITE)
# ==========================================================
FROM node:20-alpine AS node-builder

WORKDIR /app

COPY package*.json ./
RUN npm ci

COPY . .
RUN npm run build


# ==========================================================
# STAGE 2 - PHP BASE
# ==========================================================
FROM php:8.2-cli-bullseye AS php-base

RUN apt-get update && apt-get install -y \
    git curl unzip zip wget \
    libzip-dev libpng-dev libjpeg62-turbo-dev libfreetype6-dev \
    libicu-dev libonig-dev libpq-dev libssl-dev \
    autoconf build-essential pkg-config \
    && rm -rf /var/lib/apt/lists/*

# GD
RUN docker-php-ext-configure gd --with-freetype --with-jpeg

# Extensiones PHP
RUN docker-php-ext-install -j$(nproc) \
    pdo pdo_pgsql pgsql \
    mbstring bcmath exif intl \
    pcntl posix sockets zip gd

# Redis + Swoole
RUN pecl channel-update pecl.php.net \
    && pecl install redis swoole \
    && docker-php-ext-enable redis swoole

# Composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www


# ==========================================================
# STAGE 3 - COMPOSER BUILD (CORREGIDO)
# ==========================================================
FROM php-base AS composer-builder

WORKDIR /var/www

# ⚠️ IMPORTANTE: copiar TODO antes de composer install
COPY . .

RUN composer install \
    --no-dev \
    --prefer-dist \
    --optimize-autoloader \
    --no-interaction


# ==========================================================
# STAGE 4 - PRODUCTION
# ==========================================================
FROM php-base AS production

WORKDIR /var/www

# Copiar app completa ya con vendor
COPY --from=composer-builder /var/www /var/www

# Frontend build
COPY --from=node-builder /app/public/build /var/www/public/build

# Permisos Laravel
RUN mkdir -p \
    storage/framework/cache \
    storage/framework/sessions \
    storage/framework/views \
    storage/logs \
    bootstrap/cache

RUN chown -R www-data:www-data /var/www \
    && chmod -R 775 storage bootstrap/cache

# ⚠️ NO ejecutar artisan en build

EXPOSE 8000 8080

USER www-data

# Default (API)
CMD ["php", "artisan", "octane:start", "--server=swoole", "--host=0.0.0.0", "--port=8000", "--workers=auto", "--task-workers=auto"]