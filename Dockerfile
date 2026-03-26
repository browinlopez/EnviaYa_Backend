# =========================
# Stage 1: Build frontend assets
# =========================
FROM node:20-alpine AS node-builder
WORKDIR /app

# Copiar y instalar dependencias de Node
COPY package*.json ./
RUN npm ci --silent

# Copiar el código y generar assets
COPY . .
RUN npm run build

# =========================
# Stage 2: PHP base con extensiones
# =========================
FROM php:8.2-fpm-bullseye AS php-base

# Instalar herramientas del sistema y extensiones necesarias
RUN apt-get update && apt-get install -y --no-install-recommends \
    git \
    curl \
    unzip \
    wget \
    libzip-dev \
    libpng-dev \
    libjpeg-dev \
    libfreetype6-dev \
    libonig-dev \
    libssl-dev \
    pkg-config \
    build-essential \
    zlib1g-dev \
    libicu-dev \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j$(nproc) pdo pdo_mysql zip gd mbstring pcntl posix \
    && pecl channel-update pecl.php.net \
    && pecl install swoole-6.2.0 \
    && docker-php-ext-enable swoole \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/* /tmp/* /var/tmp/*

WORKDIR /var/www

# =========================
# Stage 3: Instalar dependencias Composer
# =========================
FROM php-base AS composer-builder

WORKDIR /var/www

# Copiar archivos de Composer
COPY composer.json composer.lock ./

# Instalar Composer
RUN curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer

# Instalar dependencias de Laravel optimizadas
RUN composer install --no-dev --optimize-autoloader --no-interaction --no-scripts

# Copiar resto del código
COPY . .

# Optimizar autoload
RUN composer dump-autoload --optimize

# =========================
# Stage 4: Imagen final de producción
# =========================
FROM php-base

WORKDIR /var/www

# Configurar Git como seguro
RUN git config --global --add safe.directory /var/www

# Copiar código, vendor y frontend build
COPY --chown=www-data:www-data . .
COPY --from=composer-builder --chown=www-data:www-data /var/www/vendor ./vendor
COPY --from=node-builder --chown=www-data:www-data /app/public/build ./public/build

# Configurar permisos
RUN mkdir -p storage/framework/{sessions,views,cache} \
    && mkdir -p bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache \
    && chmod -R 775 storage bootstrap/cache

# Instalar Laravel Octane
RUN curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer \
    && composer require laravel/octane:^2.1 --with-all-dependencies \
    && php artisan octane:install --server=swoole

# Cache de configuración y rutas
RUN php artisan config:cache \
    && php artisan route:cache \
    && php artisan view:cache

# Exponer puerto
EXPOSE 8000

# Iniciar Octane con Swoole
CMD ["php", "artisan", "octane:start", "--server=swoole", "--host=0.0.0.0", "--port=8000", "--workers=auto"]