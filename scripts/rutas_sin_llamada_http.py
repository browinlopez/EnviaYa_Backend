"""
QUE RUTAS NO APARECEN COMO CAMINO EN EL CODIGO DE LOS CLIENTES.

    python scripts/rutas_sin_llamada_http.py

POR QUE EXISTE, ADEMAS DE `rutas_sin_llamante.py`. Ese busca la URI de cada
ruta como SUBCADENA en el codigo de los clientes, y eso tiene un modo de falso
positivo que no se ve: una ruta cuyo camino sea una palabra corriente aparece
en el codigo por razones que no son llamarla.

El caso que lo destapo: `GET /v1/users`. El detector la daba por viva porque
`"users"` sale veinte veces en los paneles --es la clave de cache de React
Query, `useQuery(["users"], ...)`-- y ni una sola de esas veces es una llamada
a ese endpoint. Los paneles llaman a `/admin/users`, que es otra ruta.

Un falso positivo en un detector de codigo muerto no da error: deja la ruta
viva. Y esa en concreto devuelve el listado del personal con sus correos a
cualquiera con sesion, asi que el falso positivo tuvo coste.

LO QUE DISTINGUE DE VERDAD UNA LLAMADA DE UNA CLAVE DE CACHE. Lo primero que
probe fue exigir que el camino estuviera DENTRO de la llamada --`get('/x')`,
`axios.post(...)`, `fetch(...)`-- y ese buscador salio ciego: su propio grupo
de control lo delato. En la app el camino no esta dentro de la llamada:

    // features/business/data/businessApi.ts:42
    const endpoint = user_id ? '/businesses/index' : '/top-businesses-free';

Primero se elige el camino y luego se llama. Lo que separa un camino de una
clave de cache no es estar dentro de un `get(`: es LA BARRA INICIAL. Un camino
de API se escribe `'/businesses/index'`; una clave de React Query se escribe
`["users"]`, sin barra. Asi que lo que se busca es un literal de cadena que
empiece por `/` y solo lleve caracteres de camino.

Con ese criterio `/users` sigue sin aparecer ni una vez, que es el hallazgo, y
ademas se ven los caminos que se eligen antes de llamarse.

DOS NIVELES, y se informa de cual vio cada ruta:

  llamada   el camino esta dentro de un `get(...)`, `post(...)`, `fetch(...)`.
  literal   el camino aparece como literal, pero no dentro de una llamada.

El nivel `literal` es mas debil a proposito: tambien casa con una lista de
configuracion --`httpClient.ts:141` enumera las rutas que no llevan token-- y
eso no es llamarla. Una ruta que solo se vea por `literal` pide mirar el sitio.

LO QUE ESTE NO PUEDE DECIR, y por eso no borra nada:

  - un camino construido a trozos --`${base}/user` mas el id-- no se reconoce;
  - una llamada dentro de un ayudante generico tampoco, si el camino llega
    por parametro;
  - y no ve la app publicada en las tiendas, solo el codigo de hoy.

Asi que su salida son CANDIDATAS. La decision pide cruzarlas con
`rutas_sin_llamante.py` --que recorre la historia de git con el pickaxe-- y
con `rutas_sin_uso.php`, que mira el trafico de verdad.

GRUPO DE CONTROL, EN SUS DOS MITADES. Un detector puede fallar de dos maneras
y hay que probar las dos:

  VIVAS    rutas que se usan a diario. Si no las encuentra esta ciego, y su
           lista de "sin llamada" no significa nada: sale con 2 en vez de
           contestar. Esta mitad es la que tumbo la primera version.
  MUERTAS  `v1/users`, comprobada a mano: los clientes llaman `/admin/users`,
           `/users/notifications`, `/users/password` y `/users/update`, y
           ninguno llama `GET /v1/users`. Si un dia aparece como llamada, o
           alguien la empezo a usar --y entonces hay que mirar lo que publica,
           que es el personal con sus correos-- o el buscador volvio a
           confundir una clave de cache con un camino.
"""

import io
import json
import os
import re
import subprocess
import sys

AQUI = os.path.dirname(os.path.abspath(__file__))
REPO = os.path.dirname(AQUI)

# Los cuatro clientes, al lado del backend.
PADRE = os.path.dirname(os.path.dirname(REPO))
CLIENTES = [
    'EnviaYa_App/EnviaYa_App/src',
    'EnviaYa_Aliados/EnviaYa_Aliados/src',
    'EnviaYa_Admin/EnviaYa_Admin/src',
    'EnviaYa_Landing/EnviaYa_Landing/src',
]

EXT = ('.js', '.jsx', '.ts', '.tsx')

# Un literal de cadena que empieza por `/` y solo lleva caracteres de camino.
# `${}` entra porque los caminos con parametro se escriben `/user/${id}`.
LITERAL = re.compile(r"""[`'"](/[A-Za-z0-9_./\-${}]*)[`'"]""")

# El camino dentro de una llamada HTTP. Es el nivel fuerte.
LLAMADA = re.compile(
    r"""(?:get|post|put|patch|delete|fetch|request)\s*\(\s*[`'"](/?[^`'"]+)[`'"]""",
    re.I,
)

CONTROL_VIVAS = ['login', 'orders/orders', 'businesses/index']
CONTROL_MUERTA = 'users'

# RUTAS QUE NO TIENEN QUE APARECER EN NINGUN CLIENTE, y por que. Sin esta
# lista el buscador las devuelve cada vez y el que lea la salida tiene que
# volver a averiguar lo mismo; con el motivo escrito, lo que queda es una
# lista de decisiones y no de ruido.
SIN_CLIENTE = {
    'v1/webhooks/bold':
        'la llaman los servidores de Bold, no un cliente. Si apareciera en '
        'el codigo de una app seria el fallo, no la ausencia.',
    'v1/comprobantes/{id}/pdf':
        'el servidor entrega la URL ya firmada en `pdf_url` y el cliente abre '
        'ese enlace; nunca arma el camino. Viva.',
    'v1/app/errores':
        'si se llama, en `core/errores/reportar.js:44`, pero como '
        '`${API_URL}/${VERSION}/app/errores`: camino a trozos, el limite que '
        'esta cabecera declara. Viva.',
}


def ficheros():
    for rel in CLIENTES:
        base = os.path.join(PADRE, rel)

        if not os.path.isdir(base):
            continue

        for d, _, fs in os.walk(base):
            if 'node_modules' in d or '.git' in d or 'dist' in d:
                continue

            for f in fs:
                if not f.endswith(EXT):
                    continue

                # Una ruta que solo viva en una prueba del cliente no la usa
                # nadie: la prueba la mantiene viva sola.
                if '.test.' in f or '.spec.' in f:
                    continue

                yield os.path.join(d, f)


def caminos_de_los_clientes():
    """Los caminos vistos, cada uno con el nivel mas fuerte que lo vio."""
    fuera = {}

    for ruta in ficheros():
        try:
            t = io.open(ruta, encoding='utf-8', errors='replace').read()
        except OSError:
            continue

        for m in LITERAL.finditer(t):
            fuera.setdefault(m.group(1), 'literal')

        for m in LLAMADA.finditer(t):
            fuera[m.group(1)] = 'llamada'

    return fuera


def rutas_de_v1():
    s = subprocess.run(['php', 'artisan', 'route:list', '--json'],
                       cwd=REPO, capture_output=True, text=True, timeout=300).stdout
    datos = json.loads(s[s.index('['):])

    fuera = []
    for r in datos:
        if not r['uri'].startswith('v1/'):
            continue
        for v in r['method'].split('|'):
            if v == 'HEAD':
                continue
            fuera.append((v, r['uri']))

    return sorted(set(fuera))


def nivel(camino_ruta, caminos):
    """`llamada`, `literal` o None, tolerando el prefijo y los parametros."""
    sin_v1 = camino_ruta[3:] if camino_ruta.startswith('v1/') else camino_ruta

    patron = re.escape(sin_v1)
    # `{id}` de la ruta casa con `${id}` o con un trozo cualquiera.
    patron = re.sub(r'\\\{[^}]*\\\}', r'[^/]+', patron)
    # ANCLADO POR LOS DOS LADOS, y esto es lo que la mitad negativa del
    # control destapo: con el final anclado solo, la ruta `v1/users` casaba
    # con el `/admin/users` de los paneles, que es OTRA ruta --exactamente el
    # falso positivo por el que existe este fichero, colado otra vez en el
    # comparador. El camino del cliente tiene que ser el camino de la ruta
    # entero, no acabar en el.
    rx = re.compile(r'\A' + patron.rstrip('/') + r'\Z')

    visto = None
    for c, n in caminos.items():
        limpio = c.split('?')[0].strip('/')
        if limpio.startswith('v1/'):
            limpio = limpio[3:]
        if rx.match(limpio):
            if n == 'llamada':
                return 'llamada'
            visto = 'literal'

    return visto


def main():
    caminos = caminos_de_los_clientes()

    print('RUTAS DE /v1 QUE NO APARECEN COMO CAMINO EN LOS CLIENTES')
    print('=' * 78)
    print()
    print('  caminos distintos hallados: %d  (%d dentro de una llamada)'
          % (len(caminos), sum(1 for n in caminos.values() if n == 'llamada')))
    print()

    print('CONTROL --- tiene que VER lo que se usa a diario:')
    ciego = False
    for c in CONTROL_VIVAS:
        n = nivel(c, caminos)
        print('  %-9s %s' % (n or 'NO', c))
        if not n:
            ciego = True

    print()
    print('CONTROL --- y tiene que seguir SIN ver la comprobada a mano:')
    n = nivel(CONTROL_MUERTA, caminos)
    print('  %-9s %s' % (n or 'ok, nada', CONTROL_MUERTA))

    if ciego:
        print()
        print('EL BUSCADOR NO VE. No encuentra caminos de rutas que se usan a')
        print('diario, asi que su lista de "sin llamada" no significa nada.')
        print('Revisar CLIENTES y los dos patrones.')
        return 2

    if n:
        print()
        print('OJO: `v1/users` aparece ahora como "%s". O alguien la empezo a' % n)
        print('usar --y hay que mirar lo que publica, que es el personal con')
        print('sus correos-- o el buscador confundio una clave con un camino.')

    print()

    todas = rutas_de_v1()
    crudas = [(v, u) for v, u in todas if not nivel(u, caminos)]
    sin = [(v, u) for v, u in crudas if u not in SIN_CLIENTE]
    exentas = [(v, u) for v, u in crudas if u in SIN_CLIENTE]
    flojas = [(v, u) for v, u in todas if nivel(u, caminos) == 'literal']

    print('CANDIDATAS, ningun camino en los clientes  (%d de %d rutas)'
          % (len(sin), len(todas)))
    print('-' * 78)
    for v, u in sin:
        print('  %-6s %s' % (v, u))

    print()
    print('EXENTAS, no tienen que aparecer  (%d)' % len(exentas))
    print('-' * 78)
    for v, u in exentas:
        print('  %-6s %s' % (v, u))
        print('         %s' % SIN_CLIENTE[u].replace('. ', '.' + chr(10) + '         '))

    faltan = [u for u in SIN_CLIENTE if u not in [x[1] for x in exentas]]
    if faltan:
        print()
        print('  (ya aparecen en los clientes, o ya no existen, y sobran de')
        print('   SIN_CLIENTE: %s)' % ', '.join(sorted(faltan)))

    print()
    print('SOLO COMO LITERAL, nunca dentro de una llamada  (%d)' % len(flojas))
    print('-' * 78)
    for v, u in flojas:
        print('  %-6s %s' % (v, u))

    print()
    print('Son CANDIDATAS, no muertas: un camino construido a trozos o pasado')
    print('por parametro no se reconoce, y esto no ve la app ya publicada.')
    print('Cruzar con rutas_sin_llamante.py y con rutas_sin_uso.php antes de')
    print('retirar ninguna.')

    return 0


if __name__ == '__main__':
    sys.exit(main())
