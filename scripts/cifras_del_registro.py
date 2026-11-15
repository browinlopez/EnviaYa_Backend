#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""Remide las cifras que ESTADO.md, EXCEPCIONES.md y ANTES-DE-EMPUJAR.md afirman.

POR QUE EXISTE
--------------
Los tres documentos no describen reglas: describen MEDICIONES. Y una medicion
escrita envejece sin que nada avise, porque el codigo cambia y el parrafo no.
Ha pasado tres veces, y dos estan confesadas dentro del propio registro:

  - «Esta fila decia "no existe", que era verdad cuando se escribio y dejo de
    serlo sin que nadie la actualizara.»   (los `JsonResource`)
  - «Este parrafo ya decia 50 cuando eran 137.»   (los commits sin empujar)

La tercera fue el 15 de noviembre de 2026, y fueron siete a la vez: 1361
pruebas cuando eran 1373, 494 clases cuando eran 506, «No hay PHPStan/Larastan
... Decision pendiente» cuando llevaba dias siendo un paso del CI, 115
controladores cuando eran 113. Corregirlas a mano por tercera vez es
acordarse mejor, no medir.

NO ES UNA PUERTA. Como dice `AGENTS.md` §27, los verificadores de `scripts/`
son heuristicas: esto IMPRIME lo que no cuadra y solo sale 1 con `--fallar`.
Una cifra puede estar vieja a proposito --«se midio el 9 de noviembre» fecha
una decision y tiene que seguir diciendo lo de entonces-- y por eso lo decide
quien lee, no el script.

LO QUE NO MIDE, Y POR QUE
-------------------------
El numero de pruebas. Saberlo exige correr la suite entera (79 s), y contar
`test(` en los ficheros da otra cosa, porque los `dataset` multiplican. Se
pasa con `--pruebas N`, o con `--salida-de-pest fichero` para leerlo de una
corrida ya hecha. Sin ninguno de los dos esas filas se informan como NO
MEDIDAS en vez de darse por buenas: una comprobacion que no se hizo no es una
comprobacion que paso.

UN PATRON QUE NO ENCAJA ES UN ERROR, NO UN ACIERTO. Es lo unico que impide
que esto acabe siendo lo que vigila: si alguien reescribe una frase y el
patron deja de encontrarla, el informe diria «todo cuadra» estando ciego. Por
eso existe `--control`, que mete texto sintetico con todas las cifras
cambiadas a proposito y exige cazarlas TODAS.

    python scripts/cifras_del_registro.py
    python scripts/cifras_del_registro.py --control
    python scripts/cifras_del_registro.py --salida-de-pest pest.txt
"""
from __future__ import annotations

import argparse
import io
import os
import re
import subprocess
import sys

RAIZ = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
DOCS = os.path.join(RAIZ, "docs", "laravel-12")

NO_MEDIBLE = -1


# --------------------------------------------------------------- utilidades

def _sin_color(s):
    return re.sub(r"\x1b\[[0-9;]*m", "", s)


def _correr(orden, timeout=300):
    """Devuelve la salida, o '' si el programa no esta o revienta."""
    try:
        r = subprocess.run(orden, cwd=RAIZ, capture_output=True, text=True,
                           encoding="utf-8", errors="replace", timeout=timeout)
    except (OSError, subprocess.SubprocessError):
        return ""
    return _sin_color(r.stdout + r.stderr)


def _php_en(carpeta):
    base = os.path.join(RAIZ, carpeta)
    if not os.path.isdir(base):
        return NO_MEDIBLE
    return sum(1 for _, _, fs in os.walk(base) for f in fs if f.endswith(".php"))


def _texto_de_app():
    """Cada .php de `app/`, leido una sola vez."""
    for d, _, fs in os.walk(os.path.join(RAIZ, "app")):
        for f in fs:
            if f.endswith(".php"):
                p = os.path.join(d, f)
                yield io.open(p, encoding="utf-8", errors="replace").read()


def _lineas_de_los_controladores():
    base = os.path.join(RAIZ, "app", "Http", "Controllers")
    tam = []
    for d, _, fs in os.walk(base):
        for f in fs:
            if f.endswith(".php"):
                p = os.path.join(d, f)
                tam.append(len(io.open(p, encoding="utf-8", errors="replace")
                                 .read().splitlines()))
    return sorted(tam)


# ------------------------------------------------------------- las medidas

def laravel():
    m = re.search(r"Laravel Framework\s+(\d+\.\d+)", _correr(["php", "artisan", "--version"], 120))
    return m.group(1) if m else NO_MEDIBLE


def controladores():
    return _php_en("app/Http/Controllers")


def servicios():
    return _php_en("app/Services")


def modelos():
    return _php_en("app/Models")


def consultas():
    return _php_en("app/Queries")


def resources():
    return _php_en("app/Http/Resources")


def validaciones_en_linea():
    """`$request->validate(` sobre `app/`: lo que cuenta el trinquete."""
    return sum(t.count("$request->validate(") for t in _texto_de_app())


def form_requests():
    return sum(1 for t in _texto_de_app() if "extends FormRequest" in t)


def mediana_de_controladores():
    tam = _lineas_de_los_controladores()
    return tam[len(tam) // 2] if tam else NO_MEDIBLE


def controladores_sobre_50():
    return sum(1 for n in _lineas_de_los_controladores() if n > 50)


def _linea_base():
    p = os.path.join(RAIZ, "phpstan-baseline.neon")
    if not os.path.exists(p):
        return ""
    return io.open(p, encoding="utf-8", errors="replace").read()


def entradas_de_la_linea_base():
    s = _linea_base()
    return s.count("message:") if s else NO_MEDIBLE


def avisos_de_la_linea_base():
    s = _linea_base()
    if not s:
        return NO_MEDIBLE
    return sum(int(x) for x in re.findall(r"count:\s*(\d+)", s))


def clases_que_cargan():
    """Lo mide el mismo script que corre el CI, no una cuenta paralela."""
    m = re.search(r"clases cargadas:\s*(\d+)",
                  _correr(["php", os.path.join("scripts", "check_classes_load.php")]))
    return int(m.group(1)) if m else NO_MEDIBLE


def archivos_sobre_el_objetivo():
    """Lo mide `check_file_sizes.py` y no una cuenta paralela: los limites
    por carpeta viven en ese script, y dos cuentas que pueden discrepar son
    peor que una."""
    salida = _correr([sys.executable, "-I",
                      os.path.join("scripts", "check_file_sizes.py")])
    m = re.search(r"Archivos a revisar:\s*(\d+)", salida)
    return int(m.group(1)) if m else NO_MEDIBLE


def _commits(rango):
    s = _correr(["git", "rev-list", "--count", rango], 60).strip()
    return int(s) if s.isdigit() else NO_MEDIBLE


def _vecino(nombre):
    """La ruta del repo hermano, con el envoltorio que usa este arbol de
    trabajo: `Desarrollo EnviaYa/EnviaYa_X/EnviaYa_X`. Devuelve None si no
    esta, y entonces la cifra se informa como no medida en vez de como cero."""
    base = os.path.dirname(os.path.dirname(RAIZ))
    ruta = os.path.join(base, nombre, nombre)
    return ruta if os.path.isdir(os.path.join(ruta, ".git")) else None


def _commits_en(nombre, rango):
    ruta = _vecino(nombre)
    if ruta is None:
        return NO_MEDIBLE
    try:
        r = subprocess.run(["git", "rev-list", "--count", rango], cwd=ruta,
                           capture_output=True, text=True, encoding="utf-8",
                           errors="replace", timeout=60)
    except (OSError, subprocess.SubprocessError):
        return NO_MEDIBLE
    s = r.stdout.strip()
    return int(s) if r.returncode == 0 and s.isdigit() else NO_MEDIBLE


def app_sin_empujar():
    """Contra `origin/Browin_IOS`, que es la rama de verdad de la app. Contra
    `origin/main` saldrian 157, y ese numero no significa nada."""
    return _commits_en("EnviaYa_App", "origin/Browin_IOS..HEAD")


def panel_sin_empujar():
    return _commits_en("EnviaYa_Aliados", "@{u}..HEAD")


def panel_por_traer():
    """Lo que `origin/master` del panel tiene y la rama local no. No es cosa
    de empujar sino de traer, y un merge sin esto los pisa."""
    return _commits_en("EnviaYa_Aliados", "HEAD..origin/master")


def landing_sin_empujar():
    return _commits_en("EnviaYa_Landing", "origin/main..HEAD")


def commits_sobre_devs():
    return _commits("Devs..HEAD")


def devs_sobre_origen():
    return _commits("origin/Devs..Devs")


def commits_en_total():
    return _commits("origin/Devs..HEAD")


# ------------------------------------------------- que afirma cada documento
#
# Cada fila: documento, nombre legible, patron con UN grupo, como se mide.
# El patron va PEGADO a su frase, no laxo: si alguien la reescribe el patron
# deja de encajar, y eso se informa como error, no como acierto.

AFIRMACIONES = [
    ("EXCEPCIONES.md", "version de Laravel",
     r"\*\* sobre Laravel ([\d.]+),", laravel),
    ("EXCEPCIONES.md", "controladores (cabecera)",
     r"sobre Laravel [\d.]+, (\d+) controladores", controladores),
    ("EXCEPCIONES.md", "servicios",
     r"\n> (\d+) servicios, \d+ modelos", servicios),
    ("EXCEPCIONES.md", "modelos",
     r"\n> \d+ servicios, (\d+) modelos", modelos),
    ("EXCEPCIONES.md", "objetos de consulta",
     r"modelos, (\d+) objetos de consulta", consultas),
    ("EXCEPCIONES.md", "controladores (fila de la estructura)",
     r"(\d+) controladores en ~20 carpetas de dominio", controladores),
    ("EXCEPCIONES.md", "clases en Resources",
     r"`app/Http/Resources/` tiene (\d+) clases", resources),
    ("EXCEPCIONES.md", "validaciones en linea",
     r"medido: \*\*(\d+) `\$request->validate\(`", validaciones_en_linea),
    ("EXCEPCIONES.md", "FormRequest",
     r"`\$request->validate\(` y (\d+) `FormRequest`\*\*", form_requests),
    ("EXCEPCIONES.md", "mediana de los controladores",
     r"mediana \*\*(\d+)\*\*", mediana_de_controladores),
    ("EXCEPCIONES.md", "controladores de mas de 50 lineas",
     r"\*\*(\d+) de \d+\*\* controladores pasan de 50", controladores_sobre_50),
    ("EXCEPCIONES.md", "total de controladores (fila de lineas)",
     r"\*\*\d+ de (\d+)\*\* controladores pasan de 50", controladores),

    ("EXCEPCIONES.md", "archivos sobre el objetivo de lineas",
     r"`check_file_sizes.py` \| `scripts/` \| \*\*(\d+) archivos\*\*",
     archivos_sobre_el_objetivo),

    ("ANTES-DE-EMPUJAR.md", "la app sin empujar",
     r"la app va \*\*(\d+) commits\*\* por", app_sin_empujar),
    ("ANTES-DE-EMPUJAR.md", "el panel sin empujar",
     r"el panel \*\*(\d+)\*\* sobre su propia rama", panel_sin_empujar),
    ("ANTES-DE-EMPUJAR.md", "el panel por traer",
     r"del panel tiene (\d+) commits que la rama local", panel_por_traer),
    ("ANTES-DE-EMPUJAR.md", "la landing sin empujar",
     r"la landing \*\*(\d+)\*\* sobre `origin/main`", landing_sin_empujar),

    ("ESTADO.md", "clases que cargan",
     r"\*\*(\d+)\*\* clases cargan", clases_que_cargan),
    ("ESTADO.md", "avisos de la linea base",
     u"l\u00ednea base (\\d+) avisos", avisos_de_la_linea_base),
    ("ESTADO.md", "entradas de la linea base",
     u"l\u00ednea base \\d+ avisos en (\\d+) entradas", entradas_de_la_linea_base),

    ("ANTES-DE-EMPUJAR.md", "commits sobre Devs",
     r"son \*\*(\d+) commits\*\*", commits_sobre_devs),
    ("ANTES-DE-EMPUJAR.md", "Devs sobre origin/Devs",
     r"\*\*(\d+) commits por\ndelante de `origin/Devs`\*\*", devs_sobre_origen),
    ("ANTES-DE-EMPUJAR.md", "commits en total",
     r"`origin/Devs`\*\*: \*\*(\d+)\*\* en total", commits_en_total),
]

# Las que solo se saben corriendo la suite. Se alimentan por bandera.
AFIRMACIONES_DE_PRUEBAS = [
    ("EXCEPCIONES.md", "pruebas (cabecera)",
     # El salto de linea del markdown cae donde quiera: se tolera el `> `
     # intercalado. El patron original pedia el salto y no encajaba en la
     # cabecera de hoy --lo cazo la corrida de verdad, no el control, porque
     # el control traia su propia redaccion--.
     r"objetos de consulta y\s*>?\s*(\d+) pruebas"),
    ("EXCEPCIONES.md", "pruebas (red del contrato)",
     r"siguen siendo la red: \*\*(\d+) pruebas\*\*"),
    ("EXCEPCIONES.md", "pruebas (lo que ya se cumple)",
     u"\\(\u00a711\\): (\\d+) en Pest"),
    ("ESTADO.md", "pruebas (cabecera)",
     u"Laravel [\\d.]+ \u00b7 (\\d+) pruebas en verde"),
]

def cifras_que_vigila():
    """Cuantas afirmaciones tiene esta lista.

    Para que las dos frases que lo dicen en prosa --`ANTES-DE-EMPUJAR.md` y la
    tabla de herramientas de `EXCEPCIONES.md`-- no se queden en el numero de
    ayer, que seria este mismo defecto una vuelta mas arriba. Se cuenta al
    llamar, o sea despues del `+=` de abajo: las dos filas se incluyen solas y
    no hay ninguna constante que mantener."""
    return len(AFIRMACIONES) + len(AFIRMACIONES_DE_PRUEBAS)


AFIRMACIONES += [
    ("ANTES-DE-EMPUJAR.md", "cifras que vigila (prosa)",
     r"Remide las \*\*(\d+) cifras\*\*", cifras_que_vigila),
    ("EXCEPCIONES.md", "cifras que vigila (tabla)",
     r"Remide las (\d+) cifras que estos tres documentos", cifras_que_vigila),
]

SOLO_CON_SUITE = {(d, n) for d, n, _ in AFIRMACIONES_DE_PRUEBAS}


# ------------------------------------------------------------------ el motor

def comprobar(textos, pruebas):
    """(cuadran, desajustes, errores, no_medidas) sobre los textos que se le den."""
    cuadran, desajustes, errores, no_medidas = [], [], [], []

    for doc, nombre, patron, medir in AFIRMACIONES:
        hallados = re.findall(patron, textos.get(doc, ""))
        if len(hallados) != 1:
            errores.append((doc, nombre, "el patron encaja %d veces" % len(hallados)))
            continue
        dice = hallados[0]
        real = medir()
        if real == NO_MEDIBLE:
            no_medidas.append((doc, nombre, "dice %s; no se pudo medir" % dice))
        elif str(real) != str(dice):
            desajustes.append((doc, nombre, dice, str(real)))
        else:
            cuadran.append((doc, nombre, dice))

    for doc, nombre, patron in AFIRMACIONES_DE_PRUEBAS:
        hallados = re.findall(patron, textos.get(doc, ""))
        if len(hallados) != 1:
            errores.append((doc, nombre, "el patron encaja %d veces" % len(hallados)))
            continue
        dice = hallados[0]
        if pruebas is None:
            no_medidas.append((doc, nombre,
                               "dice %s; hace falta --pruebas o --salida-de-pest" % dice))
        elif str(pruebas) != str(dice):
            desajustes.append((doc, nombre, dice, str(pruebas)))
        else:
            cuadran.append((doc, nombre, dice))

    return cuadran, desajustes, errores, no_medidas


# ------------------------------------------------------------------ control
#
# Texto sintetico con TODAS las cifras puestas en 7. Si una fila no la caza,
# su patron no esta mirando donde cree, y el informe normal diria «cuadra»
# por no haber encontrado nada que comparar.

CONTROL = {
    "EXCEPCIONES.md": (
        u"> **Medido el 1 de enero de 1999** sobre Laravel 0.1, 7 controladores,\n"
        u"> 7 servicios, 7 modelos, 7 objetos de consulta y\n"
        u"> 7 pruebas.\n"
        u"`app/Models/Order/`. 7 controladores en ~20 carpetas de dominio |\n"
        u"`app/Http/Resources/` tiene 7 clases.\n"
        u"Al 1 de enero, medido: **7 `$request->validate(` y 7 `FormRequest`**\n"
        u"mediana **7**; **7 de 7** controladores pasan de 50.\n"
        u"| `check_file_sizes.py` | `scripts/` | **7 archivos** por encima |\n"
        u"Remide las 7 cifras que estos tres documentos afirman.\n"
        u"siguen siendo la red: **7 pruebas**, varias de ellas\n"
        u"- **Pruebas por funcionalidad** (\u00a711): 7 en Pest, con casos\n"
    ),
    "ESTADO.md": (
        u"**Medido el 1 de enero.** Laravel 0.1 \u00b7 7 pruebas en verde y\n"
        u"12 saltadas \u00b7 PHPStan y Pint limpios (l\u00ednea base 7 avisos en 7 "
        u"entradas, desde\n503) \u00b7 **7** clases cargan\n"
    ),
    "ANTES-DE-EMPUJAR.md": (
        u"Nada est\u00e1 empujado. Medido el **1 de enero**: son **7 commits**\n"
        u"en `validacion/integracion` sobre `Devs`, y `Devs` a su vez va "
        u"**7 commits por\ndelante de `origin/Devs`**: **7** en total.\n"
        u"la app va **7 commits** por delante, el panel **7** sobre su propia rama"
        u" y la landing **7** sobre `origin/main`. Y el master del panel tiene 7 commits que la rama local no tiene.\n"
        u"Remide las **7 cifras** que `ESTADO.md` afirma.\n"
    ),
}


def control():
    cuadran, desajustes, errores, no_medidas = comprobar(CONTROL, 7)
    esperadas = len(AFIRMACIONES) + len(AFIRMACIONES_DE_PRUEBAS)

    print("CONTROL: %d afirmaciones, todas con la cifra cambiada a 7." % esperadas)
    print()

    # Las de pruebas se alimentan con 7 a proposito, asi que tienen que
    # CUADRAR: eso ya demuestra que su patron encuentra la frase. Las demas
    # tienen que DESAJUSTAR contra la realidad del repositorio.
    fallos = []
    for doc, nombre, razon in errores:
        fallos.append("%-22s %-42s PATRON CIEGO: %s" % (doc, nombre, razon))
    for doc, nombre, _ in cuadran:
        if (doc, nombre) not in SOLO_CON_SUITE:
            fallos.append("%-22s %-42s NO LO CAZO (la realidad es 7?)" % (doc, nombre))
    for doc, nombre, _ in no_medidas:
        if (doc, nombre) in SOLO_CON_SUITE:
            fallos.append("%-22s %-42s sin cifra de pruebas" % (doc, nombre))

    if fallos:
        print("EL CONTROL FALLA. Estas filas no miran donde creen:")
        for f in fallos:
            print("  " + f)
        print()
        print("Mientras esto falle el informe normal no vale nada: puede decir")
        print("que todo cuadra simplemente por no encontrar nada que comparar.")
        return 1

    print("Caza las %d. Los patrones encajan donde tienen que encajar." % esperadas)
    return 0


def _leer(doc):
    p = os.path.join(DOCS, doc)
    return io.open(p, encoding="utf-8", errors="replace").read() if os.path.exists(p) else ""


def main():
    p = argparse.ArgumentParser(description=__doc__.split("\n")[0])
    p.add_argument("--pruebas", type=int, help="el numero de pruebas de una corrida de pest")
    p.add_argument("--salida-de-pest", dest="salida",
                   help="fichero con la salida de pest, de donde leerlo")
    p.add_argument("--control", action="store_true",
                   help="texto sintetico con las cifras cambiadas: tiene que cazarlas")
    p.add_argument("--fallar", action="store_true",
                   help="salir 1 si hay desajustes (por omision solo se informan)")
    args = p.parse_args()

    if args.control:
        return control()

    pruebas = args.pruebas
    if pruebas is None and args.salida:
        if not os.path.exists(args.salida):
            print("No existe el fichero: " + args.salida)
            return 2
        texto = _sin_color(io.open(args.salida, encoding="utf-8", errors="replace").read())
        m = re.search(r"(\d+) passed", texto)
        if not m:
            print(u"No encuentro \u00abN passed\u00bb en " + args.salida)
            return 2
        pruebas = int(m.group(1))
        print("Pruebas leidas de la corrida: %d\n" % pruebas)

    textos = {d: _leer(d) for d in ("EXCEPCIONES.md", "ESTADO.md", "ANTES-DE-EMPUJAR.md")}
    faltan = [d for d, s in textos.items() if not s]
    if faltan:
        print("No encuentro: " + ", ".join(faltan))
        return 2

    cuadran, desajustes, errores, no_medidas = comprobar(textos, pruebas)

    if errores:
        print("PATRONES QUE NO ENCAJAN  (%d)" % len(errores))
        print("-" * 78)
        print("Alguien reescribio la frase. La cifra NO se ha comprobado: hay que")
        print("arreglar el patron en este script, no dar la fila por buena.")
        for doc, nombre, razon in errores:
            print("  %-22s %-42s %s" % (doc, nombre, razon))
        print()

    if desajustes:
        print("CIFRAS QUE YA NO DESCRIBEN EL REPOSITORIO  (%d)" % len(desajustes))
        print("-" * 78)
        for doc, nombre, dice, real in desajustes:
            print("  %-22s %-42s dice %-8s es %s" % (doc, nombre, dice, real))
        print()

    if no_medidas:
        print("NO MEDIDAS  (%d)" % len(no_medidas))
        print("-" * 78)
        for doc, nombre, razon in no_medidas:
            print("  %-22s %-42s %s" % (doc, nombre, razon))
        print()

    print("=" * 78)
    print(u"  %d cuadran \u00b7 %d desajustadas \u00b7 %d sin comprobar \u00b7 %d patrones ciegos"
          % (len(cuadran), len(desajustes), len(no_medidas), len(errores)))
    print()
    print(u"Una cifra vieja no siempre es un error: \u00abse midio el 9 de noviembre\u00bb")
    print(u"fecha una decision y tiene que seguir diciendo lo de entonces. Lo")
    print(u"decide quien lee; esto solo se encarga de que lo vea.")

    return 1 if args.fallar and (desajustes or errores) else 0


if __name__ == "__main__":
    sys.exit(main())
