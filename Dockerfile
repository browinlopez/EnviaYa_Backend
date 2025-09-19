# -----------------------------
# 1. Imagen base
# -----------------------------
FROM php:8.2-fpm

# -----------------------------
# 2. Variables de entorno
# -----------------------------
ENV APP_ENV=production
ENV COMPOSER_ALLOW_SUPERUSER=1
ENV COMPOSER_HOME=/composer

# -----------------------------
# 3. Instalar dependencias del sistema
# -----------------------------
RUN apt-get update && apt-get install -y \
    git \
    unzip \
    libpq-dev \
    libzip-dev \
    zip \
    libonig-dev \
    libxml2-dev \
    curl \
    && docker-php-ext-install pdo pdo_mysql bcmath zip mbstring xml \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

# -----------------------------
# 4. Instalar Composer
# -----------------------------
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# -----------------------------
# 5. Configurar directorio de trabajo
# -----------------------------
WORKDIR /var/www

# -----------------------------
# 6. Copiar solo archivos necesarios para composer (caching)
# -----------------------------
COPY composer.json composer.lock ./
RUN composer install --no-dev --optimize-autoloader --prefer-dist

# -----------------------------
# 7. Copiar el resto del proyecto
# -----------------------------
COPY . .

# -----------------------------
# 8. Dar permisos correctos
# -----------------------------
RUN chown -R www-data:www-data /var/www/storage /var/www/bootstrap/cache \
    && chmod -R 775 /var/www/storage /var/www/bootstrap/cache

# -----------------------------
# 9. Exponer puerto y ejecutar php-fpm
# -----------------------------
EXPOSE 9000
CMD ["php-fpm"]
