# Base PHP para Laravel
FROM php:8.2-fpm

# Instalar dependencias del sistema
RUN apt-get update && apt-get install -y \
    git curl libzip-dev unzip wget \
    supervisor \
    && docker-php-ext-install pdo pdo_mysql zip \
    && apt-get clean

# Instalar Node.js (para Reverb)
RUN curl -fsSL https://deb.nodesource.com/setup_20.x | bash - \
    && apt-get install -y nodejs

# Instalar Composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Crear directorio de trabajo
WORKDIR /var/www

# Copiar todo el código del repo
COPY . .

# Instalar dependencias Laravel
RUN composer install --optimize-autoloader --no-dev

# Instalar dependencias Reverb
WORKDIR /var/www/reverb
RUN npm install

# Copiar archivo supervisor
COPY supervisord.conf /etc/supervisor/conf.d/supervisord.conf

# Exponer puertos
EXPOSE 80 443 8080 9000

# Comando de inicio
CMD ["/usr/bin/supervisord"]
