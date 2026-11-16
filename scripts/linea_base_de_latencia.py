"""
LA LINEA BASE DE LATENCIA DEL ARNES. No es latencia de produccion.

    php artisan serve --port=8099        (en otra terminal)
    php artisan reverb:start --port=8080 (en otra, Y ES OBLIGATORIO: ver abajo)
    python scripts/linea_base_de_latencia.py

Que hace: vacia el registro de latencia, corre el arnes N veces para juntar
muestras y escribe el informe de percentiles en
`docs/laravel-12/linea-base-de-latencia.md`.

PARA QUE SIRVE Y PARA QUE NO
----------------------------

El estandar (§25) pide percentiles por endpoint. La herramienta de medida ya
existe —`MedidaDeLaPeticion` escribe una linea por peticion— y lo que falta es
TRAFICO: con un solo recorrido del arnes casi ninguna ruta llega a las 20
muestras que hacen honesto un percentil, y el informe las deja en blanco a
proposito.

Esto rellena esas muestras repitiendo el recorrido. El numero que sale NO es
el tiempo que ve un tendero:

  · corre contra `php artisan serve`, que atiende de una en una y no tiene
    opcache afinado como el contenedor de produccion;
  · los datos son los de desarrollo, con su tamanio y su distribucion;
  · y el reparto de llamadas es el del arnes, no el del uso real: el arnes
    llama una vez a cada cosa, y la gente llama mil veces a la vitrina.

Sirve para UNA cosa, y es suficiente para que exista: comparar contra si
misma. Si el p95 de `v1/negocio/pedidos` se dobla entre dos tandas en la
MISMA maquina, algo empeoro. El numero honesto por endpoint sigue necesitando
unos dias de uso de verdad, y eso no lo da ninguna herramienta.

POR QUE EXIGE REVERB, Y POR QUE SE NIEGA SIN EL
-----------------------------------------------

Medido: un `broadcast()` con Reverb caido cuesta **2049 ms** —el
`connect_timeout` de 2 s que `config/broadcasting.php` pone a proposito— y con
Reverb en marcha, 372 ms la primera vez y menos despues. Seis de los siete
eventos son `ShouldBroadcastNow`, o sea que esa espera ocurre DENTRO de la
peticion.

Asi que sin Reverb, crear un pedido sale en 4,1 s y cancelarlo en 6,2 s: dos y
tres anuncios de 2 s. No es un hallazgo, es el tope funcionando; pero una
linea base recogida asi dice que las rutas que anuncian algo son 2 s mas
lentas de lo que son, y eso **parece una respuesta**. Es el mismo error que
`rutas_sin_llamante.py` evita con su grupo de control: un medidor ciego da
numeros con la misma cara que un medidor que ve.

Por eso esto comprueba que Reverb contesta ANTES de medir, y si no contesta no
mide.
"""

import io
import json
import os
import re
import socket
import subprocess
import sys
import time

AQUI = os.path.dirname(os.path.abspath(__file__))
RAIZ = os.path.dirname(AQUI)
SALIDA = os.path.join(RAIZ, 'docs', 'laravel-12', 'linea-base-de-latencia.md')

VUELTAS = int(os.environ.get('LINEA_BASE_VUELTAS', '22'))
PUERTO_API = int(os.environ.get('LINEA_BASE_PUERTO', '8099'))
PUERTO_REVERB = int(os.environ.get('REVERB_PORT', '8080'))


def contesta(puerto):
    s = socket.socket()
    s.settimeout(2)
    try:
        s.connect(('127.0.0.1', puerto))
        return True
    except OSError:
        return False
    finally:
        s.close()


def minimo_para_percentil():
    """El minimo de muestras que el informe exige, leido de la config."""
    r = subprocess.run(
        ['php', '-r',
         "require 'vendor/autoload.php';"
         "$a=require 'bootstrap/app.php';"
         "$a->make(Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap();"
         "echo config('rendimiento.minimo_para_percentil');"],
        cwd=RAIZ, capture_output=True, text=True, timeout=120)
    try:
        return int(r.stdout.strip())
    except ValueError:
        return 20


MESES = ['enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio',
         'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre']


def hoy_en_castellano():
    """La fecha en castellano sin depender del locale de la maquina.

    `strftime('%B')` devuelve 'November' en una maquina en ingles, y el
    documento que esto escribe lo lee gente que trabaja en castellano.
    """
    t = time.localtime()

    return '%d de %s de %d' % (t.tm_mday, MESES[t.tm_mon - 1], t.tm_year)


def registros():
    carpeta = os.path.join(RAIZ, 'storage', 'logs')
    return [os.path.join(carpeta, f) for f in os.listdir(carpeta)
            if f.startswith('latencia-') and f.endswith('.log')]


def main():
    print('LINEA BASE DE LATENCIA DEL ARNES')
    print('=' * 78)

    # --- las dos comprobaciones que hacen creible el numero -----------------
    if not contesta(PUERTO_API):
        print('\nEl backend no contesta en el puerto %d.' % PUERTO_API)
        print('  php artisan serve --port=%d' % PUERTO_API)
        return 2

    if not contesta(PUERTO_REVERB):
        print('\nREVERB NO CONTESTA en el puerto %d, y sin el no se mide.'
              % PUERTO_REVERB)
        print('\n  php artisan reverb:start --port=%d --hostname=127.0.0.1'
              % PUERTO_REVERB)
        print('\nMotivo: un broadcast contra un Reverb caido cuesta 2049 ms de')
        print('connect_timeout DENTRO de la peticion, asi que toda ruta que')
        print('anuncie algo saldria 2 s mas lenta de lo que es. Un numero')
        print('falso es peor que ninguno.')
        return 2

    minimo = minimo_para_percentil()
    print('\n  backend en %d: si' % PUERTO_API)
    print('  reverb  en %d: si' % PUERTO_REVERB)
    print('  vueltas del arnes: %d' % VUELTAS)
    print('  minimo de muestras por ruta que exige el informe: %d' % minimo)

    # --- se vacia el registro: mezclar tandas es mezclar codigos distintos --
    borrados = 0
    for f in registros():
        os.remove(f)
        borrados += 1
    print('  registros de latencia vaciados: %d' % borrados)
    print('\n  (se vacian a proposito: una tanda de ANTES de un cambio y otra')
    print('   de despues, en el mismo fichero, dan un percentil de las dos)')

    # --- las vueltas --------------------------------------------------------
    print('\nRecorriendo:')
    t0 = time.time()
    fallos = 0
    for i in range(1, VUELTAS + 1):
        r = subprocess.run([sys.executable, os.path.join(AQUI, 'arnes', 'correr.py')],
                           cwd=RAIZ, capture_output=True, text=True, timeout=1800)
        ok = r.returncode == 0
        if not ok:
            fallos += 1
        lineas = sum(1 for f in registros()
                     for _ in io.open(f, encoding='utf-8', errors='replace'))
        print('  vuelta %2d/%d  %s  %d lineas  %.0fs'
              % (i, VUELTAS, 'bien' if ok else 'CON FALLOS', lineas, time.time() - t0))

    if fallos:
        print('\n%d vueltas con fallos. La linea base se escribe igual, pero'
              ' un proceso que falla no deja las mismas muestras.' % fallos)

    # --- cuantas rutas llegan al minimo ------------------------------------
    cuenta = {}
    for f in registros():
        for l in io.open(f, encoding='utf-8', errors='replace'):
            m = re.search(r'"ruta":"([^"]+)".*?"verbo":"([^"]+)"', l)
            if m:
                clave = m.group(2) + ' ' + m.group(1)
                cuenta[clave] = cuenta.get(clave, 0) + 1

    con_percentil = [k for k, n in cuenta.items() if n >= minimo]
    print('\n  rutas con muestras: %d' % len(cuenta))
    print('  rutas que llegan a %d muestras: %d' % (minimo, len(con_percentil)))

    # --- el informe ---------------------------------------------------------
    r = subprocess.run(['php', os.path.join('scripts', 'percentiles_de_latencia.php')],
                       cwd=RAIZ, capture_output=True, text=True, timeout=600)
    if r.returncode != 0:
        print('\nEl informe de percentiles fallo:')
        print(r.stdout[-2000:])
        print(r.stderr[-2000:])
        return 1

    cabecera = io.open(os.path.join(AQUI, 'linea-base-cabecera.md'),
                       encoding='utf-8').read()
    cuerpo = cabecera.format(
        vueltas=VUELTAS,
        peticiones=sum(cuenta.values()),
        rutas=len(cuenta),
        con_percentil=len(con_percentil),
        minimo=minimo,
        fecha=hoy_en_castellano(),
    )

    io.open(SALIDA, 'w', encoding='utf-8', newline='\n').write(
        cuerpo + '\n```\n' + r.stdout.strip() + '\n```\n')

    print('\n  escrito: %s' % os.path.relpath(SALIDA, RAIZ))
    return 0


if __name__ == '__main__':
    sys.exit(main())
