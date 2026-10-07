# =============================================================================
# POR QUÉ IMPORTA CUÁNTO TARDA ESTE ARCHIVO EN COMPILAR
#
# Dokploy para la pila vieja ANTES de construir la nueva, así que mientras esto
# compila no hay ningún contenedor registrado en Traefik y la API responde 404.
# El hueco dura exactamente lo que dure el build: cada minuto que se le quite
# aquí es un minuto menos sin pedidos.
#
# Eso lo arregla de verdad el interruptor «Zero-downtime deployment» de Dokploy
# —está explicado en docker-compose.yml—. Lo de aquí es lo que se puede hacer
# desde el código, que es la mitad del problema y la que no depende de nadie.
# =============================================================================

# =========================
# Etapa 1: assets del frontend
# =========================
# Las vistas Blade que quedan —verificación de correo, recuperar contraseña,
# el PDF del acuerdo— usan @vite, así que hay que compilarlas aunque el panel
# viva en otro proyecto.
FROM node:20-alpine AS node-builder
WORKDIR /app

COPY package.json package-lock.json ./

# Acá hubo un `--mount=type=cache` para la caché de npm, y se quitó después de
# medirlo: con la caché tibia `npm ci` tardaba 7,7 s y sin ella 10,2 s. Dos
# segundos y medio no pagan atar el despliegue a BuildKit, porque con el
# constructor clásico esa sintaxis no falla en silencio: revienta el build.
RUN npm ci

# SOLO LO QUE VITE MIRA, Y NO EL REPOSITORIO ENTERO.
#
# Esto era `COPY . .`, así que cualquier cambio en `app/` o en `routes/`
# invalidaba la capa y volvía a compilar los assets, que no habían cambiado.
# Vite solo lee dos entradas —resources/css/app.css y resources/js/app.js— y
# Tailwind solo escanea resources/views. Copiando eso, un despliegue que toca
# únicamente PHP reutiliza los assets ya compilados.
COPY vite.config.js tailwind.config.js postcss.config.js ./
COPY resources ./resources

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

# Lo que la imagen necesita PARA SIEMPRE, en su propia capa.
#
# Va aparte de la compilación de extensiones porque la capa de abajo borra todo
# lo que ella misma instaló, y esto tiene que sobrevivir a esa limpieza.
RUN apt-get update && apt-get install -y --no-install-recommends \
    ca-certificates \
    # curl: con él comprueban su salud los contenedores de la API y de Reverb.
    curl \
    # mysqldump: lo necesita spatie/laravel-backup. Sin el cliente de MySQL la
    # copia de seguridad diaria falla todas las noches y solo se descubre el
    # día que hay que restaurar.
    default-mysql-client \
    # procps: el pgrep con que el contenedor de la cola comprueba su salud.
    procps \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

# LAS EXTENSIONES: EN PARALELO, Y SIN DEJAR EL COMPILADOR DENTRO.
#
# Dos cosas cambian respecto de antes, y las dos se notan:
#
# 1. `-j$(nproc)` y `MAKEFLAGS`. Sin ellos, gd y Swoole se compilaban con UN
#    solo núcleo: en el registro del build se veía un `cc` detrás de otro, de
#    uno en uno, durante minutos. Son las dos piezas más caras de todo el
#    archivo, y el servidor tiene varios núcleos parados mirando.
#
# 2. Los compiladores se van en la MISMA capa en que entraron. Antes
#    build-essential, autoconf, pkg-config y las cabeceras `-dev` se quedaban
#    dentro de la imagen de producción: cientos de megas que nunca se ejecutan
#    y un compilador de C a mano de quien consiga entrar al contenedor.
#    Borrarlos en otra capa no serviría de nada —la capa anterior sigue en la
#    imagen, y se puede abrir—, así que tiene que ser aquí.
#
# El `ldd` es el truco de las imágenes oficiales de Docker: en vez de adivinar
# a mano que hace falta libzip4, o libonig5, para que cada `.so` cargue, le
# pregunta al propio binario y marca esos paquetes como manuales para que el
# purge no se los lleve. Adivinar esa lista a mano es justo como se rompe esto.
#
# El `php -m` del final es la prueba: si una extensión se quedó sin su
# biblioteca, el build falla AQUÍ y no tres semanas después en producción.
RUN set -eux; \
    marcadas="$(apt-mark showmanual)"; \
    apt-get update; \
    apt-get install -y --no-install-recommends \
      autoconf \
      build-essential \
      pkg-config \
      libzip-dev \
      libpng-dev \
      libjpeg-dev \
      libfreetype6-dev \
      libonig-dev \
      libssl-dev; \
    docker-php-ext-configure gd --with-freetype --with-jpeg; \
    docker-php-ext-install -j"$(nproc)" pdo pdo_mysql zip gd mbstring pcntl posix; \
    pecl channel-update pecl.php.net; \
    MAKEFLAGS="-j$(nproc)" pecl install swoole; \
    docker-php-ext-enable swoole; \
    apt-mark auto '.*' > /dev/null; \
    [ -z "$marcadas" ] || apt-mark manual $marcadas; \
    ldd "$(php -r 'echo ini_get("extension_dir");')"/*.so \
      | awk '/=>/ { so = $(NF-1); if (index(so, "/usr/local/") == 1) next; gsub("^/(usr/)?", "", so); printf "*/%s\n", so }' \
      | sort -u | xargs -r dpkg-query --search 2>/dev/null \
      | cut -d: -f1 | sort -u | xargs -r apt-mark manual; \
    apt-get purge -y --auto-remove -o APT::AutoRemove::RecommendsImportant=false; \
    rm -rf /var/lib/apt/lists/* /tmp/pear; \
    php -m

WORKDIR /var/www

# =========================
# Etapa 3: dependencias de Composer
# =========================
FROM php-base AS composer-builder

# git y unzip son de Composer, y SOLO de Composer: nada los usa en ejecución.
# Por eso están acá, en una etapa que no llega a producción.
RUN apt-get update && apt-get install -y --no-install-recommends \
      git \
      unzip \
    && rm -rf /var/lib/apt/lists/*

# Composer sale de su imagen oficial en vez de descargar el instalador de
# getcomposer.org y pasárselo a php. Lo de antes ejecutaba en cada build un
# script bajado de internet, sin versión fijada y sin comprobar la firma: si ese
# dominio cambia o se cae, el despliegue se cae con él.
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

COPY composer.json composer.lock ./

# Sin montaje de caché, por lo mismo que en la etapa de los assets. Medido:
# 10,0 s con la caché de Composer tibia y 8,5 s sin ella —o sea, ninguna
# ganancia—. El cuello de botella nunca estuvo en bajar paquetes.
RUN composer install --no-dev --optimize-autoloader --no-interaction --no-scripts

# Acá sí se copia el repositorio entero, a diferencia de la etapa de los
# assets: `dump-autoload --optimize` recorre app/ y database/ para armar el
# mapa de clases, y su gancho `post-autoload-dump` corre `package:discover`,
# que necesita la configuración y los proveedores. Recortarlo lo rompe, y
# tampoco valdría la pena: son segundos, no minutos.
COPY . .
RUN composer dump-autoload --optimize

# =========================
# Etapa 4: imagen final
# =========================
FROM php-base

WORKDIR /var/www

COPY --chown=www-data:www-data . .
COPY --from=composer-builder --chown=www-data:www-data /var/www/vendor ./vendor
COPY --from=node-builder --chown=www-data:www-data /app/public/build ./public/build

COPY docker/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

RUN mkdir -p storage/framework/{sessions,views,cache} \
    && mkdir -p bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache \
    && chmod -R 775 storage bootstrap/cache

# Aquí había un `git config --global --add safe.directory /var/www`, y no hacía
# nada: `.git/` está en .dockerignore, así que dentro de la imagen no hay
# repositorio que marcar como seguro. Se fue junto con git.
#
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
