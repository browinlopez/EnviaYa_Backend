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

LOS DOS PUNTOS CIEGOS DE «MODELO PELADO», YA CERRADOS. Se dejan escritos
porque el cierre de cada uno dependio de algo que no era obvio.

EL PRIMERO: EL MODELO QUE VIENE DE UNA RELACION. Para saber si
`'x' => $algo` publica una tabla hay que saber de donde sale `$algo`. Un
modelo que viene de una CONSULTA se reconoce --`::find`, `->first()`,
`new X(`--; uno que viene de una RELACION tiene la misma forma que una cadena:

    $domiciliary = $user->domiciliary;      // un modelo
    $nombre = $user->name;                  // una cadena

Lo unico que las separa es saber que `domiciliary()` es una relacion
declarada. Y eso no se podia saber hasta que las relaciones tuvieron TIPO DE
RETORNO: eran 100 sin tipar de 152. Al tiparlas, `nombres_de_relacion()` puede
leerlas del codigo --85 nombres distintos-- y la pregunta se vuelve un `in`.

EL SEGUNDO: EL CUERPO ENTERO. Esto buscaba `response()->json([...])` con
lista de claves, asi que no veia el modelo devuelto SIN lista ninguna:

    return response()->json($f);

Que es el caso extremo del mismo problema, y el que menos se nota leyendo: no
hay ni una clave escrita, asi que lo que se publica es «las columnas que tenga
la tabla» y una migracion las saca solas. Lo busca `cuerpos_pelados()`.

LO QUE LOS DOS CIERRES CAMBIARON, medido como se debe --contra el arbol de
antes de arreglar los sitios, para que hubiera algo que encontrar--:

    el medidor de antes, contra ese arbol      0 modelos pelados
    este, contra el mismo arbol                2

Y en el repo de hoy, 5 candidatas -> 9. Las cuatro nuevas son cuerpos
enteros: una es falso positivo (ver `CUERPOS_DECIDIDOS`) y tres son reales.

SIGUE SIENDO UNA LISTA DE CANDIDATAS. El filtro es una heuristica sobre la
ultima asignacion DENTRO del metodo, asi que un `->select([...])` que viva en
un ayudante de otro fichero no se ve --paso, y esta escrito--. Se mira cada
una antes de tocarla.

EL TERCER PUNTO CIEGO YA NO LO ES, y era el mas grande de los tres. Durante
tres versiones esto solo miraba `response()->json([...])`, y una forma de
entidad no tiene que estar en la respuesta: puede estar en el metodo auxiliar
al que la respuesta llama, o en el cierre de un `->map()`. Dos ejemplos que
pasaron desapercibidos hasta que se automatizo:

    private function comoFicha($c) { return [...]; }    CargaDeCatalogo
    private function ficha($c)     { return [...]; }    MiConjunto

y las dos salian en el informe solo como `'data' => $algo` --una variable
pelada que el medidor descartaba--, asi que la forma de verdad no aparecia por
ningun lado. Ahora `formas_del_fichero()` recorre los dos sitios y cada fila
dice de donde salio, `[json]` o `[ayudante]`.

LO QUE ESO CAMBIO EN LA CUENTA, que es el motivo de escribirlo: las formas de
entidad pasaron de 2 a 19. Diecisiete estaban invisibles, no arregladas. Un
medidor con un agujero no dice «quedan dos»: dice «veo dos».

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

# FORMAS MIRADAS Y DECIDIDAS, con el motivo. No son pendientes.
#
# Sin esto el informe las cuenta cada vez y quien lo lea tiene que volver a
# averiguar lo mismo. Es la leccion de `SIN_CLIENTE` en
# `rutas_sin_llamada_http.py`: una lista de candidatas sin los descartes
# escritos obliga a rehacer el trabajo en cada pasada.
DECIDIDAS = {
    'app/Http/Controllers/Marketing/AdsController.php':
        'el veredicto de un cupon: `valid`, el descuento y el total. Se arma '
        'con atributos del cupon --por eso el clasificador lo ve como '
        'entidad-- pero es un si-o-no con su importe, no una entidad '
        'publicada. Un Resource aqui seria envolver una validacion.',
    'app/Http/Controllers/Business/ConsultaDeNegociosController.php':
        'la ficha del negocio. YA enumera sus claves y lo anidado YA va por '
        'Resources --propietarios, productos, resenas y promociones--, asi '
        'que lo unico que queda a mano es la envoltura. Moverla pediria '
        'pasarle media docena de piezas ya resueltas por el constructor, que '
        'es la indireccion que se declino tambien en la decima tanda.',
}

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


def corchete(texto, i):
    """El contenido del `[` que empieza en `i`, con los pares balanceados."""
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


def formas_del_fichero(s):
    """Las formas de array del fichero: (posicion, contenido, de_donde).

    DOS SITIOS, y el segundo es el tercer agujero que tenia este medidor:

      json      `response()->json([...])`, la respuesta directa
      ayudante  `return [...]` dentro de un metodo

    El segundo hacia falta porque una forma de entidad no tiene que estar en
    la respuesta: puede estar en un metodo auxiliar privado al que la
    respuesta llama. `CargaDeCatalogoController::comoFicha()` y
    `MiConjuntoController::ficha()` son dos, y las dos salian como
    `'data' => $algo` --una variable pelada que el medidor descartaba por no
    venir de una consulta--, asi que la forma de verdad no se veia por
    ninguna parte.

    Un `return [...]` sin `=>` --una lista, `return [$a, $b]`-- se clasifica
    sola a cero y no llega al informe, igual que un array de reglas de
    validacion, donde los valores son cadenas y no lecturas.
    """
    for m in re.finditer(r'response\(\)->json\s*\(', s):
        try:
            yield m.start(), argumento(s, m.end() - 1), 'json'
        except (ValueError, IndexError):
            continue

    for m in re.finditer(r'\breturn\s*\[', s):
        try:
            yield m.start(), corchete(s, s.index('[', m.start())), 'ayudante'
        except (ValueError, IndexError):
            continue


# CUERPOS ENTEROS MIRADOS Y DECIDIDOS, aparte de `DECIDIDAS` a proposito: una
# cosa es decidir sobre la forma de array de un fichero y otra sobre su
# `response()->json($x)`. Con un solo diccionario, exonerar la primera taparia
# la segunda, que es la de mas riesgo de las dos.
CUERPOS_DECIDIDOS = {
    'app/Http/Controllers/Admin/Api/PedidosApiController.php':
        'FALSO POSITIVO, y marca un limite de este medidor. `$orden` sale de '
        '`ordenesBase()`, que SI enumera sus columnas con un `->select([...])` '
        '--veintidos, una a una-- pero vive en `app/Support/Consultas/'
        'AyudasDeAdmin.php`, y esto solo lee la asignacion dentro del metodo. '
        'No publica una columna nueva sola. Para verlo habria que seguir la '
        'llamada a otro fichero, y eso ya no es un grep.',
}


def cuerpos_pelados(s):
    """`response()->json($x)` donde `$x` sale de la base: (variable, posicion).

    El segundo punto ciego, ya cerrado. No hay forma de array que medir --no
    hay ni una clave escrita--, asi que esto no pasa por `clasificar()`: va
    derecho al cubo de `crudo`, que es lo que es. Publica la tabla entera.

    El filtro es el mismo `viene_de_un_modelo()` del resto, y hace la misma
    falta: `response()->json($mensaje)` sobre una cadena no publica nada.
    """
    for m in CUERPO_PELADO.finditer(s):
        variable = m.group(1)

        if viene_de_un_modelo(variable, cuerpo_de_la_funcion(s, m.start())):
            yield variable, m.start()


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


# LAS RELACIONES QUE DECLARAN LOS MODELOS, leidas del codigo.
#
# ESTO CIERRA EL PRIMER PUNTO CIEGO, y se pudo cerrar por algo que paso fuera
# de aqui: hasta que las relaciones no tuvieron tipo de retorno no habia
# manera de reconocerlas. `$x = $user->domiciliary;` tiene exactamente la
# misma forma que `$x = $user->name;`, y una saca un modelo de la base y la
# otra es una cadena. Lo unico que las separa es saber que `domiciliary()` es
# una relacion declarada, y ahora las 152 lo dicen.
def nombres_de_relacion():
    rx = re.compile(r'public function (\w+)\s*\(\s*\)\s*:\s*'
                    r'(BelongsToMany|BelongsTo|HasManyThrough|HasOneThrough|'
                    r'HasMany|HasOne|MorphToMany|MorphMany|MorphOne|MorphTo)\b')

    nombres = set()
    modelos = os.path.join(RAIZ, 'app', 'Models')

    for d, _, fs in os.walk(modelos):
        for f in fs:
            if f.endswith('.php'):
                texto = io.open(os.path.join(d, f),
                                encoding='utf-8', errors='replace').read()
                nombres.update(m.group(1) for m in rx.finditer(texto))

    return frozenset(nombres)


RELACIONES = nombres_de_relacion()

# `$user->domiciliary`, `$pedido?->business`: acceso pelado a una relacion.
#
# Exige que el lado derecho sea SOLO eso. `$user->domiciliary->name` no entra
# --tiene dos flechas y lo que saca es una columna-- y tampoco
# `$user->domiciliary()->count()`, que es un numero.
POR_RELACION = re.compile(r'^\s*\$\w+\s*\??->\s*(\w+)\s*$')

# EL CUERPO ENTERO: `response()->json($f)`, sin lista de claves ninguna.
#
# ESTO CIERRA EL SEGUNDO PUNTO CIEGO. Es el caso extremo del modelo pelado y
# el que menos se ve: no hay ni una clave escrita, asi que lo que se publica
# es «las columnas que tenga la tabla» y una migracion las saca solas. El
# recorrido de `formas_del_fichero()` no lo veia porque busca formas de
# ARRAY, y aqui no hay array. `FacturasApiController:265` era uno.
CUERPO_PELADO = re.compile(r'response\(\)->json\s*\(\s*(\$\w+)\s*[,)]')


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

        # Y una relacion declarada tambien saca un modelo de la base, aunque
        # no se parezca a una consulta. Ver `RELACIONES`.
        por_relacion = POR_RELACION.match(rhs)

        if por_relacion and por_relacion.group(1) in RELACIONES:
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


# UN FICHERO DE MENTIRA CON UNA FORMA DE CADA CLASE. El extractor tiene que
# encontrar las dos: la de la respuesta y la del metodo auxiliar. Si solo
# encuentra una, su reparto no vale --y durante tres versiones solo encontro
# la primera, que es como `comoFicha()` y `ficha()` pasaron desapercibidas.
CONTROL_EXTRACTOR = """<?php
class X {
    public function index()
    {
        return response()->json(['a' => $m->a, 'b' => $m->b, 'c' => $m->c]);
    }

    private function comoFicha($c)
    {
        return ['d' => $c->d, 'e' => $c->e, 'f' => $c->f];
    }
}
"""


# UNA VARIABLE QUE SALE DE UNA RELACION, y otra que sale de un atributo.
#
# Las dos mitades del control del primer punto ciego cerrado. La primera tiene
# que dar `crudo`; la segunda NO, porque `$user->name` es una cadena y
# marcarla seria cambiar un punto ciego por sesenta falsos positivos --que es
# lo que paso la primera vez que se escribio la categoria `crudo`--.
CONTEXTO_POR_RELACION = """$domiciliary = $user->domiciliary;"""
CONTEXTO_POR_ATRIBUTO = """$nombre = $user->nombre_de_pila;"""
CONTROL_RELACION = """[
    'a' => $m->a,
    'b' => $m->b,
    'c' => $m->c,
    'domiciliary' => $domiciliary,
]"""
CONTROL_ATRIBUTO = """[
    'a' => $m->a,
    'b' => $m->b,
    'c' => $m->c,
    'nombre' => $nombre,
]"""

# Y el cuerpo entero, con su mitad que NO tiene que salir: la misma forma
# sobre una variable que no viene de la base.
CONTROL_CUERPO = """<?php
class X {
    public function uno()
    {
        $f = Invoice::findOrFail($id);
        return response()->json($f);
    }

    public function dos()
    {
        $texto = implode(',', $cosas);
        return response()->json($texto);
    }
}
"""


def control():
    """Las mitades del control. Devuelve que fallo, o None si todo bien.

    `extractor`      no encuentra las dos clases de forma del fichero
    `clasificador`   no reparte bien los trozos sinteticos
    """
    e = veredicto(*clasificar(CONTROL_ENTIDAD))
    i = veredicto(*clasificar(CONTROL_INFORME))
    c = veredicto(*clasificar(CONTROL_CRUDO, CONTEXTO_CRUDO))
    n = veredicto(*clasificar(CONTROL_NO_CRUDO, CONTEXTO_NO_CRUDO))
    r = veredicto(*clasificar(CONTROL_RELACION, CONTEXTO_POR_RELACION))
    a = veredicto(*clasificar(CONTROL_ATRIBUTO, CONTEXTO_POR_ATRIBUTO))

    de_donde = sorted(d for _, _, d in formas_del_fichero(CONTROL_EXTRACTOR))

    cuerpos = [v for v, _ in cuerpos_pelados(CONTROL_CUERPO)]

    print('CONTROL --- los trozos escritos a mano:')
    print('  %-9s con forma de entidad                  (entidad)' % (e or 'nada'))
    print('  %-9s con forma de informe                  (informe)' % (i or 'nada'))
    print('  %-9s tres claves y un MODELO pelado        (crudo)' % (c or 'nada'))
    print('  %-9s variables peladas que NO son modelos  (no crudo)' % (n or 'nada'))
    print('  %-9s un modelo que viene de una RELACION   (crudo)' % (r or 'nada'))
    print('  %-9s un atributo, que NO es un modelo      (no crudo)' % (a or 'nada'))
    print('  %-9s el cuerpo entero, y solo el que es modelo'
          % (','.join(cuerpos) or 'nada'))
    print('  %-9s las dos clases de forma del fichero de mentira'
          % (','.join(de_donde) or 'nada'))
    print('  %-9d relaciones declaradas leidas de app/Models' % len(RELACIONES))

    # Se devuelve QUE fallo y no un si/no, porque los dos modos de fallo
    # piden mirar sitios distintos: si el clasificador reparte mal, se mira
    # `clasificar()`; si el extractor ve de menos, se mira
    # `formas_del_fichero()`. Un mensaje que los confunda manda al lector al
    # sitio equivocado, y eso paso la primera vez que se probo esto
    # rompiendolo: el extractor quedo medio ciego y el guion dijo que el
    # clasificador no separaba.
    if de_donde != ['ayudante', 'json']:
        return 'extractor'

    if not (e == 'entidad' and i == 'informe'
            and c == 'crudo' and n != 'crudo'):
        return 'clasificador'

    # Los dos puntos ciegos cerrados, cada uno con sus DOS mitades: que vea lo
    # que tiene que ver, y que no vea lo que no. Sin la segunda mitad, las dos
    # pasarian marcando cualquier variable, que es un medidor peor que el
    # ciego: el ciego calla, este llena el informe de falsos positivos y hace
    # que nadie se lo crea.
    if not (r == 'crudo' and a != 'crudo'):
        return 'relaciones'

    if cuerpos != ['$f']:
        return 'cuerpo'

    return None


def main():
    print('FORMAS DE RESPUESTA EN app/Http, SEPARADAS POR LO QUE SON  (v3)')
    print('=' * 78)
    print()

    fallo = control()

    if fallo == 'extractor':
        print()
        print('EL EXTRACTOR VE DE MENOS. En el fichero de mentira no encuentra')
        print('las dos clases de forma --la de `response()->json([...])` y la')
        print('de un `return [...]` en un metodo auxiliar--, asi que lo que no')
        print('salga abajo no quiere decir que no exista. Mirar')
        print('`formas_del_fichero()`. No se informa.')
        return 2

    if fallo == 'clasificador':
        print()
        print('EL CLASIFICADOR NO SEPARA. No distingue un trozo con forma de')
        print('entidad de uno con forma de informe, asi que su reparto de abajo')
        print('no significaria nada. Mirar `clasificar()`. No se informa.')
        return 2

    if fallo == 'relaciones':
        print()
        print('EL MODELO QUE VIENE DE UNA RELACION NO SE RECONOCE, o se')
        print('reconoce de mas y marca cualquier atributo. Depende de leer los')
        print('tipos de retorno de `app/Models`; si una relacion nueva no los')
        print('lleva, no esta en la cuenta de arriba. Mirar `RELACIONES` y')
        print('`POR_RELACION`. No se informa.')
        return 2

    if fallo == 'cuerpo':
        print()
        print('EL CUERPO ENTERO NO SE VE, o se ve de mas. `response()->json($x)`')
        print('sin lista de claves es el caso de mas riesgo --publica las')
        print('columnas que tenga la tabla-- y es el que menos se nota leyendo.')
        print('Mirar `cuerpos_pelados()`. No se informa.')
        return 2

    print()

    cubos = {'crudo': [], 'entidad': [], 'decidida': [], 'informe': [], 'dudoso': []}

    for d, _, fs in os.walk(os.path.join(RAIZ, 'app', 'Http')):
        if YA_MIGRADO.replace('/', os.sep) in d:
            continue

        for f in sorted(fs):
            if not f.endswith('.php'):
                continue

            ruta = os.path.join(d, f)
            rel = os.path.relpath(ruta, RAIZ).replace('\\', '/')
            s = sin_comentarios(io.open(ruta, encoding='utf-8', errors='replace').read())

            for pos, arg, de_donde in formas_del_fichero(s):
                fle, ind, calc, crudo = clasificar(
                    arg, cuerpo_de_la_funcion(s, pos))
                cual = veredicto(fle, ind, calc, crudo)

                if cual == 'entidad' and rel in DECIDIDAS:
                    cual = 'decidida'

                if cual:
                    cubos[cual].append((rel, s.count('\n', 0, pos) + 1,
                                        fle, ind, calc, crudo, de_donde))

            # El cuerpo entero no tiene forma de array que repartir: es
            # `crudo` por definicion. Ver `cuerpos_pelados()`.
            for _, pos in cuerpos_pelados(s):
                cual = 'decidida' if rel in CUERPOS_DECIDIDOS else 'crudo'
                cubos[cual].append((rel, s.count('\n', 0, pos) + 1,
                                    0, 0, 0, 1, 'cuerpo'))

    rotulos = {
        'crudo': 'MODELO PELADO --- publica la tabla entera; lo primero por arreglar',
        'entidad': 'FORMAS DE ENTIDAD --- esto es lo que §04 quiere en un Resource',
        'decidida': 'MIRADAS Y DECIDIDAS --- no son pendientes; el motivo, debajo',
        'informe': 'INFORMES --- agregados; un Resource aqui seria ceremonia',
        'dudoso': 'A MIRAR A MANO --- ni una cosa ni la otra',
    }

    for cual in ('crudo', 'entidad', 'decidida', 'informe', 'dudoso'):
        filas = cubos[cual]
        print('%s  (%d)' % (rotulos[cual], len(filas)))
        print('-' * 78)

        for rel, linea, fle, ind, calc, crudo, de_donde in sorted(filas):
            print('  %s:%d   [%s]' % (rel, linea, de_donde))

            if cual == 'decidida':
                motivo = DECIDIDAS.get(rel) or CUERPOS_DECIDIDOS[rel]

                for trozo in motivo.split('. '):
                    print('        %s' % trozo.strip())
                continue

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
