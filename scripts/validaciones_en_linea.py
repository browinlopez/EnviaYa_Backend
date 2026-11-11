"""
LAS VALIDACIONES EN LINEA, UNA POR UNA, CON EL MOTIVO POR EL QUE SIGUEN AHI.

El marcador decia «quedan 69» y eso no es accionable: dentro hay casos que se
mueven a un `FormRequest` en cinco minutos y casos que NO SE DEBEN mover,
porque moverlos cambia la respuesta que recibe un cliente ya publicado.
Mezclados, el numero solo sirve para sentirse mal.

Este detector los separa, y lo que dice de cada uno:

  CONVERTIBLE            nada en medio: la validacion es lo primero del
                         metodo y las reglas estan escritas ahi. Se extrae.

  AYUDANTE PARAMETRIZADO las reglas salen de un ayudante privado que recibe
                         algo mas que la peticion —`bool $parcial`— para que
                         `store` valide estricto y `update` con `sometimes`.
                         Comprobado en los `validar*` de la API de
                         administracion: se llaman dos veces, una por verbo, y
                         el de cupones pasa ademas el id a ignorar en la regla
                         `unique`. Se puede convertir, pero `rules()` tiene
                         que mirar el verbo y la ruta: es una decision, no una
                         mudanza.

  REGLAS PRESTADAS       `validate($this->algo())`: las reglas viven en otro
                         metodo. Mismo caso, mas facil.

  ORDEN DE LA RESPUESTA  hay una busqueda o un `abort_if` ANTES de validar.
                         Un `FormRequest` valida antes de entrar al metodo,
                         asi que quien hoy recibe 404 por un id que no existe
                         recibiria 422 hablandole del cuerpo. Es un cambio de
                         contrato, no una mejora. Y cuando esa busqueda acota
                         por la sesion, ADEMAS es la autorizacion: validar
                         primero seria contestar sobre un recurso que no se
                         tiene derecho a ver.

  EL CUERPO SE TOCA      un `merge()` o un `replace()` antes: el metodo
                         arregla el cuerpo que manda una app vieja antes de
                         validarlo. Un `FormRequest` validaria el cuerpo sin
                         arreglar y rechazaria a la app publicada.

  SALIDA TEMPRANA        hay un `return` antes de validar, tipicamente para
                         versiones viejas de la app. La validacion no es la
                         primera puerta y no puede serlo.

  DOS EN UN METODO       el metodo valida dos veces segun la rama. Un
                         `FormRequest` valida una sola vez, y forzarlo cambia
                         CUAL de los dos 422 recibe quien llama. §20 lo
                         permite expresamente.

  SIN LLAMANTE           la ruta no la llama nadie (ver
                         `scripts/rutas_sin_llamante.py`). Escribir una
                         prueba y extraer una regla de codigo pendiente de
                         retirarse es invertir al reves.

  FUERA DE UNA RUTA      esta en un servicio, no en un controlador de ruta.

    python scripts/validaciones_en_linea.py
    python scripts/validaciones_en_linea.py --convertibles

NO CUENTA `Validator::make(` NI `validateWithBag(`: son otra cosa —validacion
armada a mano y salida con bolsa para una vista— y mezclarlas inflaria el
numero sin que ninguna se pueda mover.

DOS COSAS QUE SE EQUIVOCARON AL ESCRIBIRLO, por si alguien lo amplia:

  1. El trozo «antes de validar» cortaba justo en la llamada, asi que en
     `return $request->validate([...])` se quedaba con ese `return` y lo
     informaba como «salida temprana»: el motivo era la misma linea que
     estaba clasificando. Ocho de los «motivos» eran eso.
  2. La vitalidad de la ruta se miraba POR ARCHIVO.
     `CategoryBusinessController` tiene dos rutas con llamante y cuatro sin,
     asi que el archivo entero salia de un color o del otro. `route:list` da
     `Clase@metodo`: no hace falta adivinar.

Un detector que inventa motivos es peor que no tener detector, asi que
cualquier categoria nueva se comprueba a mano contra dos o tres casos antes
de creersela.
"""
import json
import os
import re
import subprocess
import sys

if hasattr(sys.stdout, 'reconfigure'):
    sys.stdout.reconfigure(encoding='utf-8', errors='replace')

AQUI = os.path.dirname(os.path.abspath(__file__))
REPO = os.path.dirname(AQUI)

LLAMADA = '$request->validate('

BARRA = chr(92)
PREFIJO = 'App' + BARRA + 'Http' + BARRA + 'Controllers' + BARRA

METODO = re.compile(
    r'^    (?:(public|protected|private)\s+)?function\s+(\w+)\s*\(([^)]*)\)', re.M,
)

# Lo que, estando ANTES de la validacion, impide moverla. Se informa el
# PRIMERO que coincida, asi que el orden va de mas fuerte a mas debil: una
# busqueda con 404 delante es mas estructural que un `return` de un caso
# particular, y `AreasApiController::update` tiene los dos.
ESTORBOS = [
    ('EL CUERPO SE TOCA', re.compile(r'\$request->(merge|replace)\(')),
    ('ORDEN DE LA RESPUESTA', re.compile(
        r'(findOrFail|firstOrFail|->sole\(|findOr\(|abort_if|abort_unless)')),
    ('SALIDA TEMPRANA', re.compile(r'^\s*return\s', re.M)),
]

REGLAS_PRESTADAS = re.compile(r'validate\(\s*\$this->')

# Comentarios, para poder preguntar si ANTES de la validacion queda codigo de
# verdad o solo explicaciones.
COMENTARIOS = [
    # De bloque: `.` tiene que cruzar lineas, y no codicioso para no juntar
    # dos comentarios distintos con el codigo de en medio.
    re.compile(r'/\*.*?\*/', re.S),
    # De linea: aqui `.` NO debe cruzar lineas, o se come el resto del
    # archivo. Por eso van en dos patrones y no en uno con `re.S`.
    re.compile(r'//[^\r\n]*'),
]


def sin_comentarios(texto):
    for patron in COMENTARIOS:
        texto = patron.sub('', texto)

    return texto.strip()

# Un ayudante privado que recibe algo mas que la peticion.
AYUDANTE_PARAMETRIZADO = re.compile(r'\$request\s*,\s*\S')

ORDEN = [
    'CONVERTIBLE',
    'SOLO A VECES',
    'AYUDANTE PARAMETRIZADO',
    'REGLAS PRESTADAS',
    'ORDEN DE LA RESPUESTA',
    'EL CUERPO SE TOCA',
    'SALIDA TEMPRANA',
    'HAY CODIGO ANTES: MIRARLO',
    'DOS EN UN METODO',
    'SIN LLAMANTE',
    'FUERA DE UNA RUTA',
]


def corre(orden):
    return subprocess.run(
        orden, cwd=REPO, capture_output=True, text=True,
        encoding='utf-8', errors='replace',
    ).stdout


def rutas_sin_llamante():
    """Las URIs sin llamante, de la lista compartida. Vacio si no esta."""
    ruta = os.path.join(AQUI, 'rutas-candidatas.json')

    if not os.path.exists(ruta):
        return set(), False

    with open(ruta, encoding='utf-8') as f:
        cfg = json.load(f)

    return {uri for g in cfg['grupos'].values() for _, uri in g}, True


def estado_por_metodo():
    """'Ruta/De/La/Clase@metodo' -> 'viva' | 'sin llamante'."""
    muertas, hay_lista = rutas_sin_llamante()

    try:
        rutas = json.loads(corre(['php', 'artisan', 'route:list', '--json']))
    except ValueError:
        return {}, hay_lista

    estados = {}

    for r in rutas:
        accion = r.get('action') or ''

        if '@' not in accion:
            continue

        clase, metodo = accion.split('@', 1)
        clave = clase.replace(PREFIJO, '').replace(BARRA, '/') + '@' + metodo

        # Un metodo puede servir varias rutas: basta UNA con llamante.
        if r['uri'] not in muertas:
            estados[clave] = 'viva'
        else:
            estados.setdefault(clave, 'sin llamante')

    return estados, hay_lista


def validaciones_del_archivo(fuente):
    """Un diccionario por cada metodo del archivo que valide en linea."""
    ms = list(METODO.finditer(fuente))
    salida = []

    for i, m in enumerate(ms):
        fin = ms[i + 1].start() if i + 1 < len(ms) else len(fuente)
        cuerpo = fuente[m.start():fin]
        cuantas = cuerpo.count(LLAMADA)

        if cuantas == 0:
            continue

        antes = cuerpo[:cuerpo.find(LLAMADA)]

        # La firma del metodo no cuenta como «codigo antes».
        antes = antes[antes.find('{') + 1:] if '{' in antes else antes

        # Ni el principio de la propia sentencia: se corta en el ultimo `;`,
        # `{` o `}`, y lo que venga despues —`$datos = `, `return `— es de la
        # linea que se esta clasificando.
        corte = max(antes.rfind(';'), antes.rfind('{'), antes.rfind('}'))
        recortado = antes[:corte + 1] if corte >= 0 else ''

        # Cuantos bloques abiertos hay encima de la validacion. Cero = esta en
        # el cuerpo del metodo; mas de cero = dentro de un `if`, un `foreach`,
        # un `try`...
        profundidad = recortado.count('{') - recortado.count('}')
        antes = recortado

        salida.append({
            'profundidad': profundidad,
            'metodo': m.group(2),
            'visibilidad': m.group(1) or 'public',
            'firma': m.group(3),
            'antes': antes,
            'cuantas': cuantas,
            'cuerpo': cuerpo,
        })

    return salida


def clasificar(v, estado):
    """
    El motivo mas fuerte primero.

    EL AYUDANTE PARAMETRIZADO VA ANTES DE «FUERA DE UNA RUTA», y el orden
    importa: un `validarBanner()` privado no es la accion de ninguna ruta, asi
    que tecnicamente esta «fuera de una ruta» — pero eso no dice nada util.
    Lo que hay que saber de el es que lo llaman `store` y `update`, y por eso
    sus reglas llevan un `$parcial` dentro. Con el orden al reves, los ocho
    `validar*` de la API de administracion salian etiquetados como si fueran
    codigo de un servicio.
    """
    if estado == 'sin llamante':
        return 'SIN LLAMANTE'

    if v['cuantas'] > 1:
        return 'DOS EN UN METODO'

    # DENTRO DE UN `if`, O SEA QUE A VECES NO VALIDA NADA.
    #
    # `ConsultaDeNegociosController::index()` solo valida `user_id` cuando
    # llega: `if ($userId) { $request->validate([...]) }`. Un `FormRequest`
    # valida SIEMPRE, asi que convertirlo cambia que pasa con una peticion
    # que hoy no pasa por esa puerta. Se puede hacer con `sometimes`, pero
    # hay que leer caso por caso que significa «a veces».
    #
    # Se detecta por profundidad de llaves y no buscando `if`: asi tambien
    # caza un `foreach`, un `try` o un `match`, y no se cuela un `if` que ya
    # estaba cerrado antes de la validacion.
    if v['profundidad'] > 0:
        return 'SOLO A VECES'

    # ¿QUEDA CODIGO ANTES DE LA VALIDACION? La pregunta es esa y no «¿esta
    # alguno de los estorbos que se me ocurrieron?».
    #
    # Las tres de `MediosApiController` salian CONVERTIBLES y no lo son: el
    # 404 lo lanza `nombreDeEntidad()`, un ayudante, no un `abort_if` a la
    # vista. El propio archivo lo explica en un comentario. Buscando patrones
    # conocidos eso no se caza nunca, porque cualquier llamada puede abortar.
    #
    # Asi que la regla es conservadora: si se ejecuta ALGO antes, moverlo
    # cambia el orden y hace falta que lo lea una persona. Los patrones de
    # ESTORBOS ya no deciden si se puede mover — solo ponen una etiqueta mejor
    # cuando se reconoce el caso.
    codigo_antes = sin_comentarios(v['antes'])

    if codigo_antes:
        for rotulo, patron in ESTORBOS:
            if patron.search(v['antes']):
                return rotulo

        # Los `validar*` tambien tienen codigo antes —`$regla = $parcial ? …`—
        # pero su etiqueta propia dice mucho mas que «mirarlo»: ya se sabe lo
        # que hay que mirar.
        if v['visibilidad'] != 'public' and AYUDANTE_PARAMETRIZADO.search(v['firma']):
            return 'AYUDANTE PARAMETRIZADO'

        return 'HAY CODIGO ANTES: MIRARLO'

    if REGLAS_PRESTADAS.search(v['cuerpo']):
        return 'REGLAS PRESTADAS'

    if v['visibilidad'] != 'public' and AYUDANTE_PARAMETRIZADO.search(v['firma']):
        return 'AYUDANTE PARAMETRIZADO'

    if estado == 'sin ruta':
        return 'FUERA DE UNA RUTA'

    return 'CONVERTIBLE'


def main():
    solo_convertibles = '--convertibles' in sys.argv[1:]
    estados, hay_lista = estado_por_metodo()

    # Del ARBOL DE TRABAJO y no de `HEAD`: quien corre esto quiere saber
    # donde esta ahora, no donde estaba en el ultimo commit. Leyendo `HEAD`,
    # una validacion que acabas de extraer sigue contando y parece que el
    # trabajo no sirvio.
    listado = corre(['git', 'grep', '-l', '--fixed-strings', LLAMADA, '--', 'app'])
    archivos = [l.strip() for l in listado.strip().splitlines() if l.strip()]

    hallazgos = []

    for archivo in sorted(archivos):
        with open(os.path.join(REPO, archivo), encoding='utf-8', errors='replace') as f:
            fuente = f.read()
        clase = archivo.replace('app/Http/Controllers/', '').replace('.php', '')

        for v in validaciones_del_archivo(fuente):
            estado = estados.get(clase + '@' + v['metodo'], 'sin ruta')
            hallazgos.append((
                clasificar(v, estado),
                archivo.replace('app/', ''),
                v['metodo'],
                v['cuantas'],
            ))

    print()
    print('LAS VALIDACIONES EN LINEA, CON EL MOTIVO POR EL QUE SIGUEN AHI')
    print('=' * 80)

    if not hay_lista:
        print()
        print('  !! Falta scripts/rutas-candidatas.json, asi que no se puede')
        print('     saber cuales estan sobre rutas que nadie llama: esas van a')
        print('     salir como vivas y el numero de convertibles sale alto.')

    if not estados:
        print()
        print('  !! `php artisan route:list --json` no devolvio nada, asi que')
        print('     TODAS van a salir como «fuera de una ruta». Revisa que la')
        print('     aplicacion arranque antes de creerte el informe.')

    total = 0

    for grupo in ORDEN:
        filas = sorted(h for h in hallazgos if h[0] == grupo)
        cuantas = sum(f[3] for f in filas)
        total += cuantas

        if not filas or (solo_convertibles and grupo != 'CONVERTIBLE'):
            continue

        print()
        print('%s  (%d)' % (grupo, cuantas))
        print('-' * 80)

        for _, archivo, metodo, n in filas:
            marca = '  x' + str(n) if n > 1 else ''
            print('  %-58s %s()%s' % (archivo, metodo, marca))

    convertibles = sum(h[3] for h in hallazgos if h[0] == 'CONVERTIBLE')

    print()
    print('=' * 80)
    print('  %d validaciones en linea en total' % total)
    print('  %d CONVERTIBLES: nada en medio, se extraen' % convertibles)
    print('  %d con un motivo para quedarse' % (total - convertibles))
    print()
    print('Un motivo no es una excusa permanente: `ORDEN DE LA RESPUESTA` deja de')
    print('aplicar si la busqueda se mueve a `authorize()`, y `EL CUERPO SE TOCA`')
    print('deja de aplicar el dia que la app vieja no este en ningun telefono.')
    print('Pero hasta entonces mover la regla CAMBIA lo que recibe un cliente')
    print('publicado, y eso no es refactorizar.')
    print()

    return 0


if __name__ == '__main__':
    sys.exit(main())
