# Base PHP para Laravel
FROM php:8.2-fpm

# Instalar dependencias del sistema
RUN apt-get update && apt-get install -y \
    git curl libzip-dev unzip wget libpng-dev libjpeg-dev libfreetype6-dev \
    supervisor nginx \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install pdo pdo_mysql zip gd \
    && apt-get clean

# Instalar Composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Crear directorio de trabajo
WORKDIR /var/www

# Copiar todo el código del repo
COPY . .

# Instalar dependencias Laravel
RUN composer install --optimize-autoloader --no-dev

# Crear directorio de logs
RUN mkdir -p /var/log/supervisor

# Configurar Nginx
COPY nginx.conf /etc/nginx/sites-available/default

# Copiar archivo supervisor
COPY supervisord.conf /etc/supervisor/conf.d/supervisord.conf

# Configurar Laravel
RUN php artisan config:cache || true
RUN php artisan route:cache || true

# Dar permisos necesarios
RUN chown -R www-data:www-data /var/www/storage /var/www/bootstrap/cache

# Exponer puertos
EXPOSE 80 443 8080 9000

# Comando de inicio
CMD ["/usr/bin/supervisord", "-c", "/etc/supervisor/conf.d/supervisord.conf"]
