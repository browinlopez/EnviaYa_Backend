"""
¿LAS LLAMA ALGUIEN? LA PREGUNTA, CONTESTADA CONTRA EL CODIGO.

`Documentacion/SUPERFICIE-DE-API-SIN-USO.md` daba la pregunta por imposible:
«la app 1.0.8 esta publicada en las dos tiendas y no se puede leer que llama:
es un binario en telefonos que no controlamos».

Eso era mirar al sitio equivocado. **El codigo fuente de ese binario esta en
el repo**, en el commit que se publico, y la historia de git tiene todos los
demas. Asi que no hace falta el servidor ni esperar un mes de trafico: se
lee.

Tres pasadas, de menos a mas fuerte:

  1. EL CONTROL. Busca primero rutas que SI se llaman. Sin esto, un buscador
     roto devuelve «nadie la llama» treinta y una veces y parece una
     respuesta. Si el control falla, el informe se detiene.

  2. CADA VERSION PUBLICADA. La app en los commits de 1.0.0 a 1.0.12, mas los
     tres paneles web y el propio backend. Cubre lo que esta corriendo hoy en
     cualquier telefono que no se haya actualizado.

  3. TODA LA HISTORIA (pickaxe). `git log -S` sobre los 200 y pico commits de
     la app: ¿existio esa cadena alguna vez? Es la que convierte «no la llama
     nadie» en «nunca la llamo nadie».

    python scripts/rutas_sin_llamante.py
    python scripts/rutas_sin_llamante.py --raiz "C:/ruta/al/espacio"

DONDE ESPERA ENCONTRAR LOS CLIENTES. Un nivel por encima de este repositorio:
cada proyecto va envuelto en su carpeta, con su Documentacion al lado, asi que
desde `EnviaYa_Backend/EnviaYa_Backend` la raiz del espacio de trabajo son dos
niveles arriba. Si falta un cliente, se dice y no se da por «nadie lo llama»:
un cliente que no se pudo leer no es un cliente que no llama.

LO QUE ESTO NO PUEDE VER, y conviene tenerlo escrito: algo de fuera de estos
cuatro repositorios —una integracion de un aliado, un script interno, una
prueba de carga— no aparece por ningun lado. Para eso esta
`scripts/rutas_sin_uso.php`, que mira el trafico de verdad.
"""
import json
import os
import re
import subprocess
import sys

# La consola de Windows abre en la pagina de codigos del sistema y los
# acentos del informe salen como basura. No es cosmetico: «RESEÑAS» ilegible
# hace dudar de si el dato tambien se leyo mal.
if hasattr(sys.stdout, 'reconfigure'):
    sys.stdout.reconfigure(encoding='utf-8', errors='replace')

AQUI = os.path.dirname(os.path.abspath(__file__))
LISTA = os.path.join(AQUI, 'rutas-candidatas.json')


def raiz_por_omision():
    """Dos niveles arriba: EnviaYa_Backend/EnviaYa_Backend -> el espacio."""
    return os.path.abspath(os.path.join(AQUI, '..', '..', '..'))


def correr(cwd, orden, timeout=300):
    """Devuelve (salida, fallo). `fallo` es None si el comando se pudo correr."""
    try:
        r = subprocess.run(orden, cwd=cwd, capture_output=True, text=True, timeout=timeout)
    except FileNotFoundError:
        return '', 'no se encontro git'
    except subprocess.TimeoutExpired:
        return '', 'tardo demasiado'
    except OSError as e:
        return '', str(e)

    # `git grep` devuelve 1 cuando no hay coincidencias: eso no es un fallo.
    if r.returncode not in (0, 1):
        return '', (r.stderr or '').strip().splitlines()[:1] or ['codigo ' + str(r.returncode)]

    return r.stdout.strip(), None


def aguja(uri):
    """El camino como literal COMPLETO, no como trozo de otro.

    Esto era `--fixed-strings` con el camino pelado, y asi buscado `users`
    sale en `/admin/users`, en `/users/update` y en `useQuery(["users"])`.
    Las tres cosas son otra ruta o ninguna, y el tercer caso es el que dejo
    `GET /v1/users` viva mientras publicaba el personal con sus correos.

    Lo que separa un camino de API de una clave de cache es LA BARRA
    INICIAL: un camino se escribe `'/users'`, una clave `["users"]`. Y lo
    que separa `/users` de `/users/update` es que el literal se CIERRA ahi.
    Asi que la aguja pide comilla, barra, el camino, y comilla:

        users                 ->  ['"`]/users['"`]
        creditos/tienda/{id}  ->  ['"`]/creditos/tienda/[^/'"`]+['"`]

    Lo que esta aguja NO ve, y por eso lo de abajo son candidatas y no
    muertas: un camino armado a trozos, `` `${base}/users` ``, donde lo que
    precede al camino no es una comilla sino `}`.
    """
    camino = uri[3:] if uri.startswith('v1/') else uri
    camino = camino.strip('/')

    patron = re.escape('/' + camino)
    # `{id}` de la ruta casa con `${id}` o con un trozo cualquiera.
    patron = re.sub(r'\\\{[^}]*\\\}', r'[^/\'"`]+', patron)

    return '[\'"`]' + patron + '[\'"`]'


def es_prueba(fichero):
    return '.test.' in fichero or '.spec.' in fichero or '__tests__' in fichero


def buscar(raiz, cliente, texto):
    """¿Esta `texto` en ese cliente? (hallazgo, motivo_si_no_se_pudo)

    `hallazgo` es None, `'codigo'` o `'pruebas'`. La tercera posibilidad
    existe porque la encontro el caso de `GET /v1/ads/featured`: su unica
    aparicion en toda la app es una lista dentro de `httpClient.test.ts`.
    Una ruta a la que solo llama la prueba de un cliente no la llama nadie
    —la prueba la mantiene viva sola—, y contarla como llamante es dejar
    viva una ruta muerta, que es justo el error que no avisa.
    """
    cwd = os.path.join(raiz, cliente['repo'].replace('/', os.sep))

    if not os.path.isdir(os.path.join(cwd, '.git')):
        return None, 'no esta en ' + cwd

    salida, fallo = correr(cwd, [
        'git', 'grep', '-l', '-E', texto, cliente['ref'], '--', cliente['dir'],
    ])

    if fallo:
        return None, str(fallo)

    ficheros = [x for x in salida.splitlines() if x.strip()]

    if not ficheros:
        return None, None

    if all(es_prueba(x) for x in ficheros):
        return 'pruebas', None

    return 'codigo', None


def main():
    argv = sys.argv[1:]
    raiz = raiz_por_omision()

    if '--raiz' in argv:
        raiz = argv[argv.index('--raiz') + 1]

    with open(LISTA, encoding='utf-8') as f:
        cfg = json.load(f)

    clientes = cfg['clientes']
    # Los DOS estados de la lista: lo ya retirado --que se vuelve a medir
    # porque es el registro-- y lo que sigue en el router sin decidir.
    rutas = [(v, u) for g in cfg['grupos'].values() for v, u in g]
    rutas += [(v, u) for g in cfg['pendientes']['grupos'].values() for v, u in g]

    print()
    print('LAS %d RUTAS SIN LLAMANTE, CONTRA EL CODIGO DE LOS CLIENTES' % len(rutas))
    print('=' * 78)
    print()
    print('  raiz    ' + raiz)
    print()

    # ---------------------------------------------------------------- #
    # 1 · EL CONTROL: si esto falla, lo de abajo no significa nada     #
    # ---------------------------------------------------------------- #
    print('CONTROL — rutas que SI se llaman, para probar que el buscador ve:')
    control_ok = True

    for texto in cfg['control']['rutas']:
        donde, motivos = [], []

        for c in clientes:
            hallazgo, no_pudo = buscar(raiz, c, aguja(texto))

            if no_pudo:
                motivos.append(c['rotulo'] + ': ' + no_pudo)
            elif hallazgo == 'codigo':
                donde.append(c['rotulo'])

        if donde:
            print('  ok    %-28s %s' % (texto, ', '.join(donde)))
        else:
            control_ok = False
            print('  FALLA %-28s no la encuentra en ningun cliente' % texto)

            for m in motivos[:3]:
                print('        ' + m)

    print()

    if not control_ok:
        print('!! El buscador no encuentra rutas que SI se llaman, asi que un')
        print('   «nadie» de aqui en adelante no querria decir nada. Revisa la')
        print('   raiz, los refs de rutas-candidatas.json y que los clientes')
        print('   esten clonados. No se sigue.')
        print()

        return 2

    # ---------------------------------------------------------------- #
    # 2 · CADA VERSION PUBLICADA                                       #
    # ---------------------------------------------------------------- #
    ausentes = []
    con_llamante = []
    de_pruebas = []
    ilegibles = {}

    todos_los_grupos = list(cfg['grupos'].items())
    todos_los_grupos += [('PENDIENTE · ' + g, f)
                         for g, f in cfg['pendientes']['grupos'].items()]

    for grupo, filas in todos_los_grupos:
        print('-' * 78)
        print(grupo.upper())
        print('-' * 78)

        for verbo, uri in filas:
            texto = aguja(uri)
            donde = []

            solo_pruebas = []

            for c in clientes:
                hallazgo, no_pudo = buscar(raiz, c, texto)

                if no_pudo:
                    ilegibles[c['rotulo']] = no_pudo
                elif hallazgo == 'codigo':
                    donde.append(c['rotulo'])
                elif hallazgo == 'pruebas':
                    solo_pruebas.append(c['rotulo'])

            if donde:
                dicho = ', '.join(donde)
            elif solo_pruebas:
                dicho = 'solo en pruebas de ' + ', '.join(solo_pruebas)
            else:
                dicho = 'nadie'

            print('  %-7s %-46s %s' % (verbo, uri, dicho))

            if donde:
                con_llamante.append((verbo, uri, donde))
            else:
                ausentes.append((verbo, uri))

                if solo_pruebas:
                    de_pruebas.append((verbo, uri, solo_pruebas))

        print()

    # ---------------------------------------------------------------- #
    # 3 · TODA LA HISTORIA: ¿existio esa cadena alguna vez?            #
    # ---------------------------------------------------------------- #
    hist = cfg['historia']
    cwd = os.path.join(raiz, hist['repo'].replace('/', os.sep))
    nunca = []
    alguna_vez = []

    print('=' * 78)
    print('TODA LA HISTORIA DE %s' % hist['repo'].split('/')[0].upper())
    print()

    if not os.path.isdir(os.path.join(cwd, '.git')):
        print('  no se pudo: no esta en ' + cwd)
    else:
        total, _ = correr(cwd, ['git', 'rev-list', '--all', '--count'])
        print('  %s commits recorridos con el pickaxe (git log -S)' % (total or '?'))
        print()

        for verbo, uri in ausentes:
            salida, fallo = correr(cwd, [
                'git', 'log', '--all', '--pickaxe-regex', '-S', aguja(uri),
                '--oneline', '--', hist['dir'],
            ], timeout=600)

            if fallo:
                print('  %-7s %-46s no se pudo: %s' % (verbo, uri, fallo))

                continue

            n = len([x for x in salida.splitlines() if x.strip()])

            if n:
                alguna_vez.append((verbo, uri, n))
                print('  %-7s %-46s estuvo, en %d commits' % (verbo, uri, n))
            else:
                nunca.append((verbo, uri))

        print('  %d rutas que NUNCA aparecieron en la historia de la app' % len(nunca))

    # ---------------------------------------------------------------- #
    print()
    print('=' * 78)
    print('EN LIMPIO')
    print()
    print('  %d sin llamante en ningun cliente ni version publicada' % len(ausentes))
    print('  %d con llamante — esas NO se retiran' % len(con_llamante))

    if alguna_vez:
        print('  %d que estuvieron y ya no: fueron de una version vieja' % len(alguna_vez))

    print()

    if con_llamante:
        print('SE QUEDAN, ESTA MEDIDO:')

        for v, u, d in con_llamante:
            print('  %-7s %-46s %s' % (v, u, ', '.join(d)))

        print()

    if de_pruebas:
        print('SOLO LAS LLAMA UNA PRUEBA DE CLIENTE — cuentan como ausentes, y')
        print('hay que borrar tambien esa prueba, o volvera a darlas por vivas:')

        for v, u, d in de_pruebas:
            print('  %-7s %-46s %s' % (v, u, ', '.join(d)))

        print()

    if ilegibles:
        print('CLIENTES QUE NO SE PUDIERON LEER — un cliente ilegible NO es un')
        print('cliente que no llama, asi que los «nadie» de arriba valen menos:')

        for rotulo, motivo in ilegibles.items():
            print('  - %s: %s' % (rotulo, motivo))

        print()

    print('Esto no ve lo que viva fuera de estos repositorios: una integracion')
    print('de un aliado, un script interno, una prueba de carga. Para eso esta')
    print('scripts/rutas_sin_uso.php, que mira el trafico de verdad.')
    print()

    return 0


if __name__ == '__main__':
    sys.exit(main())
