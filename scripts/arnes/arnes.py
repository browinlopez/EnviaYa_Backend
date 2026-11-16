"""
Arnés de validación de procesos: infraestructura compartida.

No comprueba nada por sí mismo. Da el cliente HTTP, los tokens, el acceso a la
base y la forma de reportar, para que cada archivo de procesos se lea como una
lista de pasos y no como fontanería.

Ver `LEEME.md` para cómo se corre y para qué sirve.
"""

import json
import os
import subprocess
import sys
import time
import urllib.error
import urllib.request

AQUI = os.path.dirname(os.path.abspath(__file__))

# La raíz del proyecto, deducida de dónde vive este archivo. Con una ruta
# absoluta escrita a mano esto solo funcionaba en una máquina.
REPO = os.path.dirname(os.path.dirname(AQUI))

BASE = os.environ.get('ARNES_BASE', 'http://127.0.0.1:8099')


class Fallo(Exception):
    """Un paso que no hizo lo que tenía que hacer."""


# ------------------------------------------------------------------ tokens

# Un usuario de verdad por rol, elegido por lo que PUEDE hacer y no por su
# número de rol: el tendero tiene que ser dueño del negocio 1 y el domiciliario
# tiene que estar vinculado a él, o la mitad de los procesos dan 403 con razón.
ACUNAR = r"""
use App\Models\User;
use Illuminate\Support\Facades\DB;

$quien = [
  'comprador' => DB::table('buyer')->value('user_id'),
  'tendero' => DB::table('owner')
      ->join('owner_busines', 'owner.owner_id', '=', 'owner_busines.owner_id')
      ->where('owner_busines.busines_id', 1)->value('owner.user_id'),
  'domiciliario' => DB::table('business_domiciliary')
      ->join('domiciliary', 'domiciliary.domiciliary_id', '=', 'business_domiciliary.domiciliary_id')
      ->where('business_domiciliary.busines_id', 1)->value('domiciliary.user_id'),
  'admin' => User::where('rol', 4)->value('user_id'),
  'conjunto' => DB::table('complex_staff')->value('user_id'),
];

foreach ($quien as $rol => $uid) {
  if (! $uid) { echo $rol . "=SIN_USUARIO\n"; continue; }
  $u = User::find($uid);
  $u->tokens()->where('name', 'arnes')->delete();
  echo $rol . '=' . $u->createToken('arnes')->plainTextToken . "\n";
}
"""

REVOCAR = (
    "echo \\Illuminate\\Support\\Facades\\DB::table('personal_access_tokens')"
    "->where('name', 'arnes')->delete();"
)


def _tinker(guion):
    r = subprocess.run(
        ['php', 'artisan', 'tinker', '--execute', guion],
        capture_output=True, text=True, cwd=REPO,
    )
    return (r.stdout or '') + (r.stderr or '')


def tokens():
    """Acuña un token por rol contra la base de desarrollo.

    No se guardan en ningún archivo: viven en memoria mientras corre el arnés y
    se revocan al terminar. Un token de administración en un fichero del
    repositorio es justo lo que no hay que dejar por ahí.
    """
    salida = _tinker(ACUNAR)

    out = {}
    for linea in salida.splitlines():
        linea = linea.strip()
        if '=' not in linea or 'SIN_USUARIO' in linea:
            continue
        clave, valor = linea.split('=', 1)
        # Un token de Sanctum es `<id>|<40 caracteres>`: el largo descarta las
        # líneas de adorno que tinker imprime alrededor.
        if len(valor.strip()) > 30:
            out[clave.strip()] = valor.strip()

    if not out:
        raise Fallo('no se pudo acunar ningun token:\n' + salida[-400:])

    return out


def revocar():
    _tinker(REVOCAR)


TK = tokens()


# -------------------------------------------------------------- peticiones

def pedir(metodo, uri, rol=None, cuerpo=None, token=None, archivo=None, espera=None):
    """Una petición. Devuelve (codigo, datos, milisegundos)."""
    url = BASE + '/' + uri.lstrip('/')
    datos_bin = None
    cabeceras = {'Accept': 'application/json'}

    t = token if token is not None else (TK.get(rol) if rol else None)
    if t:
        cabeceras['Authorization'] = 'Bearer ' + t

    if archivo:
        # multipart a mano, para no depender de `requests`
        limite = '----arnes' + str(int(time.time() * 1000))
        partes = []
        for k, v in (cuerpo or {}).items():
            partes.append('--' + limite)
            partes.append('Content-Disposition: form-data; name="%s"' % k)
            partes.append('')
            partes.append(str(v))
        nombre, contenido, tipo = archivo
        cabecera_archivo = (
            '--' + limite + '\r\n'
            'Content-Disposition: form-data; name="archivo"; filename="%s"\r\n'
            'Content-Type: %s\r\n\r\n' % (nombre, tipo)
        )
        cuerpo_txt = '\r\n'.join(partes) + '\r\n' if partes else ''
        datos_bin = (
            cuerpo_txt.encode('utf-8')
            + cabecera_archivo.encode('utf-8')
            + contenido
            + ('\r\n--' + limite + '--\r\n').encode('utf-8')
        )
        cabeceras['Content-Type'] = 'multipart/form-data; boundary=' + limite
    elif cuerpo is not None:
        datos_bin = json.dumps(cuerpo).encode('utf-8')
        cabeceras['Content-Type'] = 'application/json'

    req = urllib.request.Request(url, data=datos_bin, method=metodo, headers=cabeceras)

    t0 = time.time()
    try:
        with urllib.request.urlopen(req, timeout=120) as r:
            crudo = r.read()
            codigo = r.status
    except urllib.error.HTTPError as e:
        crudo = e.read()
        codigo = e.code
    except Exception as e:  # noqa: BLE001
        raise Fallo('%s %s: sin respuesta (%s)' % (metodo, uri, e)) from e
    ms = (time.time() - t0) * 1000

    try:
        datos = json.loads(crudo.decode('utf-8', 'replace'))
    except Exception:  # noqa: BLE001
        datos = {'_crudo': crudo[:300].decode('utf-8', 'replace')}

    if espera is not None:
        esperados = espera if isinstance(espera, (list, tuple)) else [espera]
        if codigo not in esperados:
            raise Fallo('%s %s -> %s (se esperaba %s): %s' % (
                metodo, uri, codigo, esperados, json.dumps(datos)[:220]))

    return codigo, datos, ms


# -------------------------------------------------------------------- base

def sql(consulta):
    """Una consulta de lectura contra la base, para comprobar efectos.

    EL JSON VA ENTRE MARCAS PROPIAS, y no es manía: la primera versión buscaba
    el primer `[` de la salida de tinker y acababa metiéndose DENTRO de un
    código de color ANSI —que empieza por ESC [— y reventaba con
    "Expecting ',' delimiter: line 1 column 4". Con marcas da igual lo que
    tinker imprima alrededor.
    """
    salida = _tinker(
        'use Illuminate\\Support\\Facades\\DB; '
        "echo '#<' . json_encode(DB::select(" + json.dumps(consulta) + ")) . '>#';"
    )

    i, j = salida.find('#<'), salida.rfind('>#')
    if i < 0 or j < 0:
        raise Fallo('la consulta no devolvio JSON: ' + salida[-250:])

    return json.loads(salida[i + 2:j])


def uno(consulta, campo):
    filas = sql(consulta)
    if not filas:
        return None
    return filas[0].get(campo)


def artisan(*args):
    r = subprocess.run(['php', 'artisan', *args], capture_output=True, text=True, cwd=REPO)
    return r.returncode, (r.stdout or '') + (r.stderr or '')


# ----------------------------------------------------------------- reporte

RESULTADOS = []
HALLAZGOS = []


def hallazgo(donde, que):
    """Algo que el backend hace mal, anotado sin tumbar el proceso.

    Separa las dos cosas que un arnés mezcla si no se tiene cuidado: que el
    arnés pidiera mal, y que el backend conteste mal. Lo segundo es el
    resultado; lo primero es ruido de quien lo escribió, y durante la primera
    pasada fue la mayoría.
    """
    HALLAZGOS.append((donde, que))


def proceso(nombre):
    """Decorador: envuelve un proceso y recoge su veredicto."""
    def envoltura(fn):
        def correr():
            t0 = time.time()
            pasos = []
            try:
                fn(pasos.append)
                RESULTADOS.append((nombre, 'BIEN', pasos, time.time() - t0, None))
            except Fallo as e:
                RESULTADOS.append((nombre, 'FALLA', pasos, time.time() - t0, str(e)))
            except Exception as e:  # noqa: BLE001
                import traceback
                RESULTADOS.append((nombre, 'ERROR', pasos, time.time() - t0,
                                   '%s: %s' % (type(e).__name__, e)
                                   + ' | ' + traceback.format_exc().splitlines()[-2].strip()))
        correr.nombre = nombre
        return correr
    return envoltura


def informe():
    bien = [r for r in RESULTADOS if r[1] == 'BIEN']
    mal = [r for r in RESULTADOS if r[1] != 'BIEN']

    print()
    print('=' * 78)
    print('  %d procesos:  %d BIEN   %d con problema' % (len(RESULTADOS), len(bien), len(mal)))
    print('=' * 78)
    for nombre, estado, pasos, seg, error in RESULTADOS:
        marca = 'OK ' if estado == 'BIEN' else '>> '
        print('%s%-44s %-5s %5.1fs  %d pasos' % (marca, nombre, estado, seg, len(pasos)))
        if error:
            print('       %s' % error[:300])

    if mal:
        print()
        print('DETALLE DE LO QUE NO PASO')
        print('-' * 78)
        for nombre, estado, pasos, seg, error in mal:
            print()
            print('  %s  [%s]' % (nombre, estado))
            for p in pasos:
                print('     . %s' % p)
            print('     X %s' % error)

    if HALLAZGOS:
        print()
        print('=' * 78)
        print('  HALLAZGOS: %d cosas que el backend hace mal' % len(HALLAZGOS))
        print('=' * 78)
        for donde, que in HALLAZGOS:
            print('  * %s' % donde)
            print('      %s' % que)

    return 1 if (mal or HALLAZGOS) else 0


def correr_todo(procesos):
    try:
        for p in procesos:
            print('... %s' % p.nombre, flush=True)
            p()
        codigo = informe()
    finally:
        # Los tokens se revocan pase lo que pase, también si algo revienta a
        # mitad: un token de administración vivo no se deja olvidado.
        revocar()

    sys.exit(codigo)
