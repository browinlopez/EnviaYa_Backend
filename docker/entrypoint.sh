#!/usr/bin/env bash
#
# ARRANQUE DE CADA CONTENEDOR
#
# La misma imagen sirve para los cuatro procesos que necesita la plataforma —la
# API, el websocket, la cola y el programador— y el primer argumento decide cuál
# es este contenedor. Un proceso por contenedor: si la cola se atasca, se
# reinicia sola sin tumbar la API.
#
# Uso:  entrypoint.sh {api|reverb|worker|scheduler}

set -euo pipefail

ROL="${1:-api}"

log() { echo "[$(date -u '+%Y-%m-%d %H:%M:%S')] [$ROL] $*"; }

# ---------------------------------------------------------------------------
# Esperar a la base de datos
#
# Docker arranca los contenedores a la vez, así que el primer `migrate` suele
# llegar antes de que MySQL acepte conexiones. Sin esta espera el contenedor
# muere, Dokploy lo reinicia, y el despliegue parece roto cuando solo iba
# rápido.
# ---------------------------------------------------------------------------
esperar_base() {
  local intentos=0
  until php -r '
    $h = getenv("DB_HOST") ?: "127.0.0.1";
    $p = getenv("DB_PORT") ?: "3306";
    $d = getenv("DB_DATABASE");
    $u = getenv("DB_USERNAME");
    $w = getenv("DB_PASSWORD");
    try { new PDO("mysql:host=$h;port=$p;dbname=$d", $u, $w, [PDO::ATTR_TIMEOUT => 3]); exit(0); }
    catch (Throwable $e) { exit(1); }
  ' 2>/dev/null; do
    intentos=$((intentos + 1))
    if [ "$intentos" -ge 60 ]; then
      log "La base de datos no respondió en 120 s."
      log "  Intentado: ${DB_HOST:-sin DB_HOST}:${DB_PORT:-3306}, base '${DB_DATABASE:-sin DB_DATABASE}'."
      log "  Si la base vive en este mismo servidor, DB_HOST no puede ser su IP"
      log "  pública: un contenedor no puede salir y volver a entrar por ella."
      log "  Usar host.docker.internal, o el nombre del servicio de la base."
      exit 1
    fi
    log "Esperando a la base de datos… ($intentos)"
    sleep 2
  done
  log "Base de datos disponible."
}

# ---------------------------------------------------------------------------
# Cachés de Laravel
#
# Se generan AQUÍ y no en el Dockerfile. `config:cache` congela el valor de
# cada env() en un archivo, y durante el build las variables del servidor
# todavía no existen: la imagen quedaba con la configuración de desarrollo
# dentro y la ignoraba en producción, porque la caché gana sobre el entorno.
# ---------------------------------------------------------------------------
cachear() {
  php artisan config:cache
  php artisan route:cache
  php artisan view:cache
  log "Configuración, rutas y vistas cacheadas."
}

esperar_base

# Las migraciones van ANTES de cachear la configuración, y no al revés.
#
# `config:cache` congela el valor de cada env() en un archivo, CACHE_STORE
# incluido, y a partir de ahí la caché gana sobre el entorno. Cacheando primero,
# el `CACHE_STORE=array` de la línea de migración no tenía ningún efecto: la
# migración seguía buscando la tabla `cache` que aún no existía.
migrar_si_toca() {
  [ "$ROL" = "api" ] || return 0

  # Con la caché en la base —que es lo normal acá— la primera migración de un
  # servidor nuevo se muerde la cola: spatie/permission vacía su caché al
  # migrar, esa caché vive en la tabla `cache`, y esa tabla la crea justamente
  # la migración que todavía no ha corrido. El contenedor moría con
  # "Base table or view not found: enviaya.cache" y Dokploy lo reiniciaba en
  # bucle, sin base y sin explicación.
  #
  # Durante la migración la caché va en memoria. El resto de la vida del
  # contenedor usa la que diga el entorno.
  log "Aplicando migraciones…"
  CACHE_STORE=array php artisan migrate --force --no-interaction

  # Enlace público de storage. Es idempotente; si ya existe no hace nada.
  php artisan storage:link --force || true
}

# ---------------------------------------------------------------------------
# Los demás esperan a que la API haya migrado
#
# Antes esto lo resolvía `depends_on: service_healthy` en el compose, pero eso
# ata el despliegue entero a que la API pase su comprobación de salud dentro
# del plazo que decida quien lance el `compose up`. Dokploy se cansaba de
# esperar y daba el despliegue por fallido —"dependency failed to start"—
# aunque la API estuviera arrancando perfectamente.
#
# Ahora cada contenedor se espera solo, y lo que espera es lo único que de
# verdad necesita: que exista la tabla `cache`. Es la que crea la primera
# migración, así que su presencia significa "las migraciones ya corrieron".
# Sin ella el trabajador de la cola muere al arrancar.
# ---------------------------------------------------------------------------
esperar_migraciones() {
  [ "$ROL" = "api" ] && return 0

  local intentos=0
  until php -r '
    $h = getenv("DB_HOST") ?: "127.0.0.1";
    $p = getenv("DB_PORT") ?: "3306";
    $d = getenv("DB_DATABASE");
    try {
      $pdo = new PDO("mysql:host=$h;port=$p;dbname=$d", getenv("DB_USERNAME"), getenv("DB_PASSWORD"), [PDO::ATTR_TIMEOUT => 3]);
      $n = $pdo->query("select count(*) from information_schema.tables where table_schema = database() and table_name = "cache"")->fetchColumn();
      exit($n ? 0 : 1);
    } catch (Throwable $e) { exit(1); }
  ' 2>/dev/null; do
    intentos=$((intentos + 1))
    if [ "$intentos" -ge 150 ]; then
      log "Las migraciones no terminaron en 5 minutos. Se arranca igual."
      return 0
    fi
    log "Esperando a que la API termine de migrar… ($intentos)"
    sleep 2
  done
  log "Migraciones aplicadas."
}

# Solo la API migra. Si lo hicieran los cuatro contenedores a la vez, dos
# podrían aplicar la misma migración y dejar la tabla a medias.
migrar_si_toca
esperar_migraciones
cachear

case "$ROL" in
  api)
    log "Arrancando Octane en el puerto 8000."
    exec php artisan octane:start \
      --server=swoole \
      --host=0.0.0.0 \
      --port=8000 \
      --workers=auto \
      --task-workers=auto
    ;;

  reverb)
    # El websocket. Escucha en 8080 dentro de la red de Docker; el dominio
    # público lo termina Traefik, que es quien pone el TLS.
    log "Arrancando Reverb en el puerto 8080."
    exec php artisan reverb:start --host=0.0.0.0 --port=8080
    ;;

  worker)
    # La cola. Acá se entregan los avisos por websocket, las notificaciones al
    # teléfono y los correos: sin este contenedor el pedido avanza igual pero
    # nadie se entera.
    #
    # `--max-time` recicla el proceso cada hora: PHP de larga vida acumula
    # memoria, y reiniciarlo a propósito sale más barato que perseguir la fuga.
    log "Arrancando el trabajador de la cola."
    exec php artisan queue:work \
      --queue=default \
      --tries=3 \
      --backoff=10 \
      --max-time=3600 \
      --sleep=3
    ;;

  scheduler)
    # El programador: resumen diario a las 7:00 y copias de seguridad de
    # madrugada. `schedule:work` es el equivalente al cron de toda la vida,
    # pero dentro del contenedor y sin tener que tocar el crontab del VPS.
    log "Arrancando el programador de tareas."
    exec php artisan schedule:work
    ;;

  *)
    log "Rol desconocido: '$ROL'. Se esperaba api, reverb, worker o scheduler."
    exit 1
    ;;
esac
