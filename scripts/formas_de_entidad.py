"""
QUE RESPUESTAS SON LA FORMA DE UNA ENTIDAD Y CUALES SON UN INFORME.

    python scripts/formas_de_entidad.py

PARA QUE. El estandar pide `JsonResource` para la forma de una entidad (§04).
Para un informe de agregados seria la ceremonia que §05 prohibe: un
`JsonResource` de «ventas de la semana, ticket medio y tendencia» no tiene
entidad detras. Asi que antes de migrar hay que separar las dos cosas, y a
ojo no se separan: las dos son un `response()->json([...])` de veinte claves.

EL CRITERIO, Y LAS DOS VECES QUE HUBO QUE AFINARLO

v1 clasificaba por numero de claves y anidamiento. Con eso metia en «por
migrar» cosas como `ConsultaDePedidosController::incomeBusiness()`, que son
22 claves de agregados.

v2 mira QUE HAY AL OTRO LADO DE CADA `=>`:

    LECTURA   `$algo->campo` o `$algo['campo']`, con moldes y `??`
    CALCULO   una llamada: `count()`, `round()`, `->filter(`, `now()`

y llama entidad a lo que es mayoritariamente lecturas. Mejor, pero seguia
confundiendo tres sitios, y los tres de la misma manera:

    'subtotal' => round($cifras['subtotal'], 2),     // Cotizacion
    'domiciliarios' => $datos['couriers'],           // Rendimiento

`$cifras['subtotal']` y `$datos['couriers']` son LECTURAS para v2 —tienen la
forma de una— pero lo que leen es un array que acaba de calcular un servicio,
no la fila de una tabla. Migrar eso a un `JsonResource` es envolver un informe
en una clase de entidad.

v3, esta, parte las lecturas en dos:

    FLECHA    `$business->name`     un atributo de una fila
    INDICE    `$cifras['subtotal']` una clave de un array local

Una forma de ENTIDAD es mayoritariamente de FLECHA. Un INFORME es de INDICE o
de CALCULO. Lo que queda en medio se lista aparte y se mira a mano, en vez de
decidirlo con un umbral.

LO QUE ESTO NO DECIDE. Un `$x->campo` tambien puede ser una lectura de un
objeto que no es un modelo —un DTO, un `stdClass` de `DB::table`—, y entonces
«entidad» es discutible. Por eso la salida se lee, no se obedece: dice donde
mirar, y hay sitios que siguen pidiendo criterio. `AdsController` es uno: el
veredicto de un cupon se arma con atributos del cupon, asi que sale como
entidad, y es mas un si-o-no que una entidad.

EL PUNTO CIEGO DE «MODELO PELADO», medido y escrito para que nadie lea esa
lista como completa. Para saber si `'x' => $algo` publica una tabla hay que
saber de donde sale `$algo`, y eso se averigua leyendo su asignacion. Un
modelo que viene de una CONSULTA se reconoce --`::find`, `->first()`,
`new X(`--; uno que viene de una RELACION, no:

    $domiciliary = $user->domiciliary;      // DomiciliaryController:190
    $business = $pedido->business;

`$x->y` tiene la misma forma que `$nombre = $user->name`, que es una cadena,
asi que no se puede separar sin saber que relaciones declara cada modelo. Dos
sitios que SI publican el modelo entero se escapan por esto, comprobados a
mano: `Domiciliary/DomiciliaryController.php:190` --`'domiciliary' =>
$domiciliary`, al lado de un `'user' => new UsuarioResource($user)` que si
esta migrado-- y `Business/ConsultaDeNegociosController.php:487`.

Y EL SEGUNDO PUNTO CIEGO, que aparecio recableando la octava tanda: esto
busca `response()->json([...])` con lista de claves, asi que no ve el modelo
devuelto COMO CUERPO ENTERO, sin lista ninguna:

    return response()->json($f);            // FacturasApiController:265

Que es el caso extremo del mismo problema. Buscar eso es otra pasada --un
`response()->json($variable)` a secas-- y se hace a mano con un grep mientras
no este escrita.

Asi que la lista de «modelo pelado» es de CANDIDATAS y esta por debajo de la
cuenta real por los dos lados. De las once de hoy, mirando una a una, algo mas
de la mitad son fugas de verdad; el resto son enteros y arrays que heredan una
asignacion parecida. Se mira cada una antes de tocarla.

GRUPO DE CONTROL, DE DOS LADOS, y los dos hacen falta:

  1. DOS TROZOS SINTETICOS escritos aca abajo, uno con forma de entidad y
     otro con forma de informe. Si el clasificador no los separa, sale con 2
     en vez de dar un informe. Son sinteticos a proposito: asi el control no
     se rompe cuando se migre un sitio de verdad y desaparezca.
  2. QUE EN EL REPO ENCUENTRE DE LOS DOS TIPOS. Un clasificador que mande
     todo a un cubo da un informe que parece limpio. Ya paso: la v2 nacio
     ciega --el argumento empieza por `[`, asi que sus comas estan al nivel 1
     y no al 0, y no partia nada-- y lo canto su propio control.
"""

import io
import os
import re
import sys

RAIZ = sys.argv[1] if len(sys.argv) > 1 else os.path.dirname(
    os.path.dirname(os.path.abspath(__file__)))

YA_MIGRADO = 'app/Http/Resources'

CONTROL_ENTIDAD = """[
    'business_id' => $business->busines_id,
    'name' => $business->name,
    'qualification' => (float) $business->qualification,
    'logo' => $business->logo ?? 'x',
]"""

CONTROL_INFORME = """[
    'subtotal' => round($cifras['subtotal'], 2),
    'descuento' => $cifras['descuento'],
    'total' => round($cifras['total'], 2),
    'domiciliarios' => $datos['couriers'],
]"""

# Tres claves bien escritas y una cruda. Tiene que salir `crudo`, no
# `entidad`: si decidiera la mayoria, esta se contaria como casi migrada
# —y es justo el reparto que tenia la v3 el dia que no vio
# `ChatController:243`—.
CONTROL_CRUDO = """[
    'chat_id' => $chat->chat_id,
    'type' => $chat->type,
    'created_at' => $chat->created_at,
    'message' => $message,
]"""

# De donde sale cada variable pelada. Va aparte porque
# `viene_de_un_modelo()` lo busca en el fichero, no dentro del array.
CONTEXTO_CRUDO = """$message = Message::create(['content' => 'x']);"""

# LA CUARTA MITAD DEL CONTROL, la que pide la categoria nueva: una variable
# pelada que NO sale de la base no se marca. Sin esto el detector daba 67
# sitios --casi todos cadenas, arrays y numeros-- y parecia un hallazgo.
CONTROL_NO_CRUDO = """[
    'status' => true,
    'token' => $token,
    'polilinea' => $polilinea,
    'data' => $datos,
    'promociones' => $lista,
    'conjuntos' => $conjuntos,
]"""

CONTEXTO_NO_CRUDO = """
$token = $user->createToken('app')->plainTextToken;
$polilinea = implode(',', $puntos);
$datos = $this->recolectar();
$lista = Promotion::where('x', 1)->get()->map(fn ($p) => (new PromocionResource($p))->resolve());
$conjuntos = ResidentialComplex::query()->get(['complex_id', 'name']);
"""


def sin_comentarios(texto):
    """Fuera comentarios, conservando los saltos para no mover las lineas."""
    fuera = []
    i, n = 0, len(texto)
    while i < n:
        dos = texto[i:i + 2]
        if dos == '/*':
            j = texto.find('*/', i + 2)
            fin = n if j == -1 else j + 2
            fuera.append('\n' * texto.count('\n', i, fin))
            i = fin
            continue
        if dos == '//':
            j = texto.find('\n', i)
            i = n if j == -1 else j
            continue
        fuera.append(texto[i])
        i += 1
    return ''.join(fuera)


def argumento(texto, desde):
    i = texto.index('(', desde)
    niv, j = 0, i
    while j < len(texto):
        c = texto[j]
        if c in '([':
            niv += 1
        elif c in ')]':
            niv -= 1
            if niv == 0:
                break
        j += 1
    return texto[i + 1:j]


MOLDE = r'(\(\s*(int|float|bool|string|array)\s*\)\s*)?'
RESPALDO = r'(\s*\?\?\s*[^,]+)?'

# `$business->name`, `$d?->contract_city`: atributo de una fila.
FLECHA = re.compile(r'^\s*' + MOLDE + r'\$\w+(\s*\??->\s*\w+)+' + RESPALDO + r'\s*$', re.X)

# `$cifras['subtotal']`: clave de un array que ya calculo alguien.
INDICE = re.compile(r'^\s*' + MOLDE + r'\$\w+(\s*\[\s*\'[^\']*\'\s*\])+' + RESPALDO + r'\s*$', re.X)

CALCULO = re.compile(r'(count|sum|avg|min|max|round|collect|whereBetween|'
                     r'groupBy|filter|reduce|diffIn|subDays|now)\s*\(')

# `'message' => $message`: una variable PELADA, sin flecha, sin indice y sin
# llamada. Es el caso de mas riesgo y el unico que no se ve leyendo la lista
# de claves, porque no hay lista: lo que se publica es «las columnas que tenga
# la tabla», asi que una migracion que añada un campo lo saca sola.
#
# Lo destapo `ChatController:243`, `'message' => $message`, que estaba al lado
# de un sitio ya migrado y por eso no llamaba la atencion. La v3 nacio sin
# verlo: clasificaba la respuesta como entidad de 3 flechas y no decia nada
# del modelo crudo que iba en la cuarta clave.
CRUDO = re.compile(r'^\s*(\$\w+)\s*$')

# De donde sale la variable. Hace falta porque `CRUDO` por si solo marca
# CUALQUIER variable pelada, y la mayoria no son modelos: `'token' => $token`
# es una cadena, `'data' => $datos` un array de un metodo privado,
# `'polilinea' => $polilinea` un texto. La primera version de esta categoria
# dio 67 sitios y al mirarlos a mano casi todos eran eso: un detector que
# encuentra sesenta y siete de algo y no se comprueba, no ha encontrado nada.
#
# Asi que se busca la asignacion de esa variable en el mismo fichero y se mira
# el lado derecho. Un modelo viene de una consulta o de un constructor.
DE_MODELO = re.compile(
    r'(::\s*(find|findOrFail|where|firstWhere|first|get|all|create|'
    r'updateOrCreate|firstOrCreate)\b'
    r'|->\s*(first|firstOrFail|get|findOrFail|find|create|save|fresh)\s*\('
    r'|\bnew\s+[A-Z]\w*\s*\('
    r'|\$request->user\s*\(\s*\))'
)

# Y lo que NO publica una tabla entera, aunque venga de una consulta. Las dos
# ultimas salieron de mirar a mano cinco de los veinticuatro que daba la
# version anterior: tres de los cinco eran benignos, y los tres por un motivo
# que se puede escribir.
NO_ES_MODELO = re.compile(
    r'(plainTextToken|->\s*pluck\s*\(|->\s*toArray\s*\(|implode\s*\(|'
    r'array_(values|map|merge|filter)\s*\(|json_encode\s*\(|'
    r'->\s*count\s*\(|->\s*sum\s*\(|(?<![\w$])(true|false|null)\s*;'

    # Ya pasa por un Resource: la forma esta escrita en otro sitio.
    # `PromocionesController:62` armaba `$lista` con un `->map()` de
    # `PromocionResource` y salia marcado por el `->get()` de la misma linea.
    r'|Resource\b'

    # La consulta ENUMERA las columnas --`->get([\'a\', \'b\'])`,
    # `->select([...])`-- asi que una columna nueva no se publica sola, que es
    # lo que esta categoria busca. `ResidentialComplexController:44`.
    r'|->\s*(get|first|select)\s*\(\s*\[\s*["\']'
    r')'
)


def piezas_de(arg):
    """Los valores de primer nivel del array literal."""
    arg = arg.strip()

    # FUERA LOS CORCHETES EXTERIORES ANTES DE PARTIR. Esto lo cazo el grupo de
    # control de la v2: el argumento empieza por `[`, asi que sus comas estan
    # al nivel 1 y no al 0; sin quitarlos no se partia nada y el informe salia
    # «limpio» estando ciego.
    if arg.startswith('[') and arg.endswith(']'):
        arg = arg[1:-1]

    fuera, niv, act = [], 0, ''
    for c in arg:
        if c in '([{':
            niv += 1
        elif c in ')]}':
            niv -= 1
        if c == ',' and niv == 0:
            fuera.append(act)
            act = ''
            continue
        act += c
    fuera.append(act)

    return fuera


FUNCION = re.compile(r'\b(?:public|private|protected|static)\s+function\s|\bfunction\s')


def cuerpo_de_la_funcion(fichero, hasta):
    """Del `function` mas cercano hacia atras hasta la posicion dada.

    Buscar la asignacion en TODO el fichero era la fuente de ruido que
    quedaba: un `'role' => $rol` o un `'closed' => $cerradas` --un entero--
    heredaba el `=` de otra variable con el mismo nombre en otro metodo, y
    salia marcado como modelo publicado. Limitarlo al metodo no es analisis
    de alcance de verdad, pero quita casi todo eso.
    """
    ultimo = 0

    for m in FUNCION.finditer(fichero, 0, hasta):
        ultimo = m.end()

    return fichero[ultimo:hasta]


def viene_de_un_modelo(variable, trozo):
    """¿La ultima asignacion de esa variable la saca de la base de datos?

    Heuristica, y se dice: se busca `$x = ...;` en el cuerpo del metodo y se
    mira el lado derecho. Lo que esto evita es contar `'token' => $token`
    como un modelo publicado, que era el 90% del ruido.
    """
    asignaciones = re.findall(
        re.escape(variable) + r'\s*=\s*([^;]{0,400});', trozo, re.S)

    if not asignaciones:
        return False

    # La ultima gana: es la que suele estar justo antes de la respuesta.
    for rhs in reversed(asignaciones):
        if NO_ES_MODELO.search(rhs):
            return False
        if DE_MODELO.search(rhs):
            return True

    return False


def clasificar(arg, fichero=''):
    """(flechas, indices, calculos, crudos) de los valores de primer nivel."""
    fle = ind = calc = crudo = 0

    for p in piezas_de(arg):
        if '=>' not in p:
            continue

        valor = p.split('=>', 1)[1].strip()

        # El orden importa: `round($cifras['x'], 2)` es un CALCULO, no un
        # indice, y `FLECHA`/`INDICE` ya no casan porque piden la linea entera.
        m = CRUDO.match(valor)

        if m and viene_de_un_modelo(m.group(1), fichero):
            crudo += 1
        elif m:
            # Una variable pelada que no sale de la base: una cadena, un
            # array, un numero. No es forma de entidad ni fuga; no cuenta.
            continue
        elif FLECHA.match(valor):
            fle += 1
        elif INDICE.match(valor):
            ind += 1
        elif CALCULO.search(valor):
            calc += 1

    return fle, ind, calc, crudo


def veredicto(fle, ind, calc, crudo=0):
    """`crudo`, `entidad`, `informe`, `dudoso`, o None si no hay que mirar.

    `crudo` gana a todo lo demas, y no por cantidad: una sola clave con un
    modelo pelado publica la tabla entera, asi que una respuesta de nueve
    claves bien escritas y una cruda es un sitio por arreglar, no una entidad
    casi lista. Por eso no entra en el reparto por mayoria.
    """
    if crudo:
        return 'crudo'

    total = fle + ind + calc

    if total < 3:
        return None

    if fle > ind + calc:
        return 'entidad'

    if ind + calc > fle:
        return 'informe'

    return 'dudoso'


def control():
    """Que separe los cuatro trozos sinteticos. Si no, no se informa nada."""
    e = veredicto(*clasificar(CONTROL_ENTIDAD))
    i = veredicto(*clasificar(CONTROL_INFORME))
    c = veredicto(*clasificar(CONTROL_CRUDO, CONTEXTO_CRUDO))
    n = veredicto(*clasificar(CONTROL_NO_CRUDO, CONTEXTO_NO_CRUDO))

    print('CONTROL --- los cuatro trozos escritos a mano:')
    print('  %-9s con forma de entidad                  (entidad)' % (e or 'nada'))
    print('  %-9s con forma de informe                  (informe)' % (i or 'nada'))
    print('  %-9s tres claves y un MODELO pelado        (crudo)' % (c or 'nada'))
    print('  %-9s variables peladas que NO son modelos  (no crudo)' % (n or 'nada'))

    return (e == 'entidad' and i == 'informe'
            and c == 'crudo' and n != 'crudo')


def main():
    print('FORMAS DE RESPUESTA EN app/Http, SEPARADAS POR LO QUE SON  (v3)')
    print('=' * 78)
    print()

    if not control():
        print()
        print('EL CLASIFICADOR NO SEPARA. No distingue un trozo con forma de')
        print('entidad de uno con forma de informe, asi que su reparto de abajo')
        print('no significaria nada. No se informa.')
        return 2

    print()

    cubos = {'crudo': [], 'entidad': [], 'informe': [], 'dudoso': []}

    for d, _, fs in os.walk(os.path.join(RAIZ, 'app', 'Http')):
        if YA_MIGRADO.replace('/', os.sep) in d:
            continue

        for f in sorted(fs):
            if not f.endswith('.php'):
                continue

            ruta = os.path.join(d, f)
            rel = os.path.relpath(ruta, RAIZ).replace('\\', '/')
            s = sin_comentarios(io.open(ruta, encoding='utf-8', errors='replace').read())

            for m in re.finditer(r'response\(\)->json\s*\(', s):
                try:
                    arg = argumento(s, m.end() - 1)
                except (ValueError, IndexError):
                    continue

                fle, ind, calc, crudo = clasificar(
                    arg, cuerpo_de_la_funcion(s, m.start()))
                cual = veredicto(fle, ind, calc, crudo)

                if cual:
                    cubos[cual].append(
                        (rel, s.count('\n', 0, m.start()) + 1, fle, ind, calc, crudo))

    rotulos = {
        'crudo': 'MODELO PELADO --- publica la tabla entera; lo primero por arreglar',
        'entidad': 'FORMAS DE ENTIDAD --- esto es lo que §04 quiere en un Resource',
        'informe': 'INFORMES --- agregados; un Resource aqui seria ceremonia',
        'dudoso': 'A MIRAR A MANO --- ni una cosa ni la otra',
    }

    for cual in ('crudo', 'entidad', 'informe', 'dudoso'):
        filas = cubos[cual]
        print('%s  (%d)' % (rotulos[cual], len(filas)))
        print('-' * 78)

        for rel, linea, fle, ind, calc, crudo in filas:
            print('  %s:%d' % (rel, linea))
            print('        flecha %d · indice %d · calculo %d · PELADO %d'
                  % (fle, ind, calc, crudo))

        if not filas:
            print('  (ninguna)')

        print()

    # Segunda mitad del control: que haya encontrado de los dos tipos.
    if not cubos['entidad'] or not cubos['informe']:
        print('OJO: solo ha encontrado de UN tipo. Puede ser verdad --si ya se')
        print('migraron todas las entidades, lo es-- o puede ser que el')
        print('clasificador este mandando todo a un cubo. Los trozos sinteticos')
        print('de arriba pasaron, asi que el mecanismo separa; conviene igual')
        print('mirar dos o tres sitios a mano antes de creerselo.')

    return 0


if __name__ == '__main__':
    sys.exit(main())
