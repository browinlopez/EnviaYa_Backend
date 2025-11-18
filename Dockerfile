# Stage 1: Build frontend assets
FROM node:20-alpine AS node-builder
WORKDIR /app
COPY package*.json ./
RUN npm ci
COPY . .
RUN npm run build

# Stage 2: PHP base WITH extensions (used for composer + final image)
FROM php:8.2-cli AS php-base

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
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install pdo pdo_mysql zip gd mbstring \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /var/www

# Stage 3: Install Composer dependencies
FROM php-base AS composer-builder

COPY composer.json composer.lock ./
RUN curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer
RUN composer install --no-dev --optimize-autoloader --no-interaction --no-scripts

COPY . .
RUN composer dump-autoload --optimize

# Stage 4: Final production image with Octane + Swoole
FROM php-base

WORKDIR /var/www

# Copy app code, vendor and frontend assets
COPY --chown=www-data:www-data . .
COPY --from=composer-builder --chown=www-data:www-data /var/www/vendor ./vendor
COPY --from=node-builder --chown=www-data:www-data /app/public/build ./public/build

# Set permissions
RUN mkdir -p storage/framework/{sessions,views,cache} \
    && mkdir -p bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache \
    && chmod -R 775 storage bootstrap/cache

# Install Laravel Octane & Swoole
RUN composer require laravel/octane \
    && php artisan octane:install --server=swoole

# Cache configs and routes
RUN php artisan config:cache \
    && php artisan route:cache \
    && php artisan view:cache

# Expose HTTP port
EXPOSE 8000

# Start Octane server
CMD ["php", "artisan", "octane:start", "--server=swoole", "--host=0.0.0.0", "--port=8000", "--workers=auto"]
