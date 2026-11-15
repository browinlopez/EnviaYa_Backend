#!/usr/bin/env python3
"""
BARRIDO DE SABOTAJE SOBRE LAS CONSTANTES QUE DECIDEN DINERO Y PERMISOS.

Por que existe: el 14 de noviembre de 2026, al mover `AyudasDeAdmin`, se
descubrio por casualidad que `Payment::COBRADOS` --la bandera `paid` de todo el
panel-- no tenia NI UNA prueba: poniendo `['rejected']`, es decir haciendo que
un pago rechazado contara como cobrado, pasaban las 1356.

Si una se escapo por casualidad, hay que mirar las demas a proposito. Cada
mutacion de aqui es un error SEMANTICO con sintaxis valida: no un `throw`, no un
cambio que reviente al cargar. La suite completa decide, no un filtro, porque
filtrar seria dar por supuesto donde esta la cobertura, que es justo lo que se
esta midiendo.

Lee la salida asi: FALLA = defendido. PASA = nadie lo vigila.
"""
import io
import json
import os
import re
import subprocess
import sys
import time

# La raiz del repositorio, deducida de donde vive este fichero: `scripts/` esta
# dentro de ella. Estaba escrita a mano, con la ruta absoluta de una maquina
# concreta, que es inservible para cualquier otra persona y rompe el script en
# cuanto alguien clona el repositorio en otro sitio.
RAIZ = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))

# LA LISTA VIVE EN UN FICHERO DE DATOS, `scripts/barrido-mutaciones.json`.
#
# Estaba escrita dentro de este script, y eso hacia que anadir una constante
# fuera editar codigo. Son datos: etiqueta, fichero, texto viejo, texto
# saboteado y que error representa. Con `--lista otra.json` se corre un
# subconjunto, que es lo que hace util volver a barrer solo una zona.
#
# LAS QUE EMPIEZAN POR «CONTROL» SON EL CONTROL, y van primero: su respuesta se
# conoce de antemano. Si el control no da lo esperado, el barrido esta ciego y
# sus demas respuestas no valen nada -- un detector que no encuentra lo que SE
# que esta pasa en verde sin mirar.
LISTA_POR_DEFECTO = os.path.join(
    os.path.dirname(os.path.abspath(__file__)), "barrido-mutaciones.json"
)
# El cerrojo vive junto a la lista, no en la raiz: ahi no se cuela en un commit
# por un `git add .` distraido.
CERROJO_RUTA = os.path.join(
    os.path.dirname(os.path.abspath(__file__)), ".barrido-en-marcha"
)



def cargar_mutaciones():
    ruta = LISTA_POR_DEFECTO
    if "--lista" in sys.argv:
        ruta = sys.argv[sys.argv.index("--lista") + 1]
        if not os.path.isabs(ruta):
            ruta = os.path.join(RAIZ, ruta)

    if not os.path.exists(ruta):
        # Un volcado de pila no es un mensaje: quien se equivoca al teclear la
        # ruta tiene que leer QUE falta, no donde reventO Python.
        print("No existe la lista " + ruta)
        print("La de por defecto es " + os.path.relpath(LISTA_POR_DEFECTO, RAIZ))
        sys.exit(2)

    try:
        with io.open(ruta, encoding="utf-8") as f:
            crudo = json.load(f)
    except json.JSONDecodeError as e:
        print("La lista " + ruta + " no es JSON valido: " + str(e))
        sys.exit(2)

    # La lista va bajo `mutaciones`, con la documentacion en `_`, que es como
    # `scripts/rutas-candidatas.json` documenta la suya: el fichero de datos se
    # explica a si mismo y no hay un segundo sitio que se quede viejo. Se
    # admite tambien una lista pelada, por si alguien pasa la suya con `--lista`.
    datos = crudo["mutaciones"] if isinstance(crudo, dict) else crudo

    faltan = [d for d in datos
              if not all(k in d for k in ("etiqueta", "fichero", "viejo", "nuevo", "dano"))]
    if faltan:
        print("Entradas incompletas en " + ruta + ": " + str(len(faltan)))
        sys.exit(2)

    if not datos:
        print("La lista de " + ruta + " esta vacia: no hay nada que barrer.")
        sys.exit(2)

    return ruta, [(d["etiqueta"], d["fichero"], d["viejo"], d["nuevo"], d["dano"])
                  for d in datos]


RUTA_LISTA, MUTACIONES = cargar_mutaciones()

RX = re.compile(r"Tests:\s+(.*)")


def leer(p):
    return io.open(p, encoding="utf-8", newline="").read()


def escribir(p, s):
    io.open(p, "w", encoding="utf-8", newline="").write(s)


def suite():
    """Devuelve (veredicto, resumen, nombres). Veredicto: FALLA / PASA / ERROR.

    `ERROR` existe porque es el fallo que ya engano una vez hoy: si `pest` se
    cae --por memoria, por un fichero que no compila-- no imprime ni «failed»
    ni resumen, y leer eso como «ninguna prueba fallo» convierte una corrida
    ciega en un «no esta cubierto» falso. Una corrida sin resumen NO es un
    resultado: es una corrida que no ocurrio.
    """
    r = subprocess.run(
        [r".\vendor\bin\pest", "--colors=never"],
        cwd=RAIZ, capture_output=True, text=True, shell=True, timeout=1800,
        # `pest` escribe UTF-8 y Windows lee cp1252 por defecto: la primera
        # corrida murio con UnicodeDecodeError y el CONTROL la paro, que es
        # justo para lo que esta. `errors='replace'` porque lo unico que se
        # busca en la salida es ASCII --«failed», «passed», «FAILED»-- y un
        # acento ilegible no debe tumbar la medida.
        encoding='utf-8', errors='replace',
    )
    salida = (r.stdout or "") + (r.stderr or "")
    m = RX.search(salida)

    if not m:
        return "ERROR", "la suite no imprimio resumen (corrida caida)", []

    resumen = m.group(1).strip()
    nombres = sorted(set(re.findall(r"FAILED\s+(\S+)", salida)))

    # Y que haya corrido la suite ENTERA: con un puñado de pruebas, «ninguna
    # fallo» tambien es verdad sin significar nada.
    pasaron = re.search(r"(\d+)\s+passed", resumen)
    if not pasaron or int(pasaron.group(1)) < 1000:
        return "ERROR", f"corrio demasiado poco: {resumen}", nombres[:4]

    if "failed" in resumen:
        return "FALLA", resumen, nombres[:4]

    return "PASA", resumen, []


def mio(ruta):
    """¿El cerrojo que hay puesto lo puso ESTE proceso?

    Se compara el PID que lleva dentro. Es la diferencia entre soltar lo que
    uno tomo y soltar lo de otro.
    """
    try:
        return io.open(ruta, encoding="utf-8").read().strip() == str(os.getpid())
    except OSError:
        return False


def arbol_sucio():
    r = subprocess.run(["git", "status", "--porcelain", "app/"],
                       cwd=RAIZ, capture_output=True, text=True, shell=True)
    return (r.stdout or "").strip()


def restaurar_con_git(rel):
    """Deja el fichero como lo tiene el ultimo commit.

    POR QUE NO BASTA GUARDAR EL TEXTO EN MEMORIA Y REESCRIBIRLO: el 14 de
    noviembre de 2026 el sistema MATO este barrido por falta de memoria a
    mitad de la primera mutacion. Un `kill` no ejecuta el `finally`, asi que
    `ENTREGADO = 3` se quedo escrito en el arbol de trabajo -- la constante
    que decide cuando un pedido mueve dinero.

    Con `git checkout --` la reparacion no depende de que este proceso siga
    vivo: queda hecha en el disco y la puede rehacer cualquiera despues.
    """
    subprocess.run(["git", "checkout", "--", rel],
                   cwd=RAIZ, capture_output=True, text=True, shell=True)

def comprobar_pendientes():
    """Valida `barrido-pendientes.json` contra el codigo. Devuelve cuantos
    problemas encontro, e imprime cada uno."""
    ruta = os.path.join(os.path.dirname(os.path.abspath(__file__)),
                        "barrido-pendientes.json")
    if not os.path.exists(ruta):
        print("")
        print("  (sin barrido-pendientes.json: no se comprueba)")
        return 0

    try:
        datos = json.load(io.open(ruta, encoding="utf-8"))
    except ValueError as e:
        print("")
        print("  barrido-pendientes.json no es JSON valido: " + str(e))
        return 1

    # Nada puede estar en las dos listas: una constante o se barre o esta
    # pendiente, y en las dos a la vez significa que alguien movio una y se
    # dejo la otra.
    barridas = set()
    for etq, _rel, _v, _n, _d in MUTACIONES:
        m = re.search(r"(\w+)::(\w+)", etq)
        if m:
            barridas.add(m.groups())

    problemas = 0
    filas = 0
    for grupo in ("muertas", "sin_prueba", "con_prueba"):
        for f in datos.get(grupo, []):
            filas += 1
            clave = (f["clase"], f["const"])
            etiqueta = "%s::%s" % clave

            if clave in barridas:
                print("  EN LAS DOS LISTAS   " + etiqueta)
                problemas += 1

            fichero = os.path.join(RAIZ, *f["fichero"].split("/"))
            if not os.path.exists(fichero):
                print("  FALTA EL FICHERO    " + etiqueta
                      + "  (" + f["fichero"] + ")")
                problemas += 1
                continue

            # Que siga declarada, y con el valor que la lista dice.
            texto = leer(fichero)
            decl = re.search(r"public const " + f["const"]
                             + r"\s*=\s*(.*?);", texto, re.S)
            if decl is None:
                print("  YA NO EXISTE        " + etiqueta
                      + "  -- borrada, o renombrada")
                problemas += 1
                continue
            valor = " ".join(decl.group(1).split())
            if valor != f["valor"]:
                print("  CAMBIO DE VALOR     " + etiqueta
                      + "  dice " + f["valor"] + ", es " + valor)
                problemas += 1

            # Y que el grupo siga siendo el suyo: si a una de `sin_prueba` le
            # escribieron su prueba, deja de estar sin prueba.
            if grupo == "sin_prueba" and f["tests"]:
                print("  GRUPO EQUIVOCADO    " + etiqueta
                      + "  esta en sin_prueba y la lista le pone pruebas")
                problemas += 1
            if grupo == "con_prueba" and not f["tests"]:
                print("  GRUPO EQUIVOCADO    " + etiqueta
                      + "  esta en con_prueba y la lista no le pone ninguna")
                problemas += 1

    print("")
    print("  " + str(filas) + " pendientes comprobadas")
    return problemas


def main():
    print("BARRIDO DE SABOTAJE -", len(MUTACIONES), "mutaciones")
    # Que lista corrio: con dos --la completa y una por zona-- un informe que
    # no lo diga no se puede volver a reproducir.
    print("lista:", os.path.relpath(RUTA_LISTA, RAIZ), "\n")
    print("FALLA = defendido.  PASA = nadie lo vigila.\n")

    # `--comprobar`: SOLO VALIDA LA LISTA Y SALE, sin tocar nada.
    #
    # Existe porque me pase dos veces lo mismo: para ver si la lista cargaba
    # bien lance el barrido y lo corte con `head -2`. Eso NO es mirar una
    # lista, es arrancar un barrido que escribe codigo malo -- y la segunda
    # vez coincidio con otro corriendo. El arreglo no es tener mas cuidado:
    # es que exista la forma de comprobar sin ejecutar.
    #
    # Y hace la comprobacion que de verdad importa antes de gastar 40 minutos:
    # que cada texto a sustituir aparezca EXACTAMENTE una vez en su fichero.
    if "--comprobar" in sys.argv:
        problemas = 0
        for etq, rel, viejo, _nuevo, _dano in MUTACIONES:
            ruta = os.path.join(RAIZ, *rel.split("/"))
            if not os.path.exists(ruta):
                print("  FALTA EL FICHERO  " + etq + "  (" + rel + ")")
                problemas += 1
                continue
            n = leer(ruta).count(viejo)
            if n != 1:
                print("  CASA " + str(n) + " VECES      " + etq)
                problemas += 1

        # Y LA OTRA LISTA, la de lo que todavia no se barre.
        #
        # `barrido-pendientes.json` dice, de cada constante sin barrer, si
        # esta muerta, si ninguna prueba la nombra, o si alguna si. Son 57
        # filas escritas a mano y envejecen como cualquier otra cosa escrita:
        # se borra una constante, se le cambia el valor, se le escribe por fin
        # su prueba, y la lista sigue diciendo lo de antes. Se comprueba aqui
        # porque es el sitio donde ya se valida la lista hermana, y porque no
        # ejecuta nada.
        problemas += comprobar_pendientes()

        print("")
        print("  " + str(len(MUTACIONES)) + " mutaciones, "
              + str(problemas) + " con problema")
        sys.exit(1 if problemas else 0)

    # UN SOLO BARRIDO A LA VEZ, Y SE COMPRUEBA PRIMERO.
    #
    # Dos barridos sobre el mismo arbol se pisan: uno restaura el fichero que
    # el otro acaba de mutar, y los dos informan de cosas que no pasaron.
    #
    # Va ANTES de la guarda de arbol sucio a proposito. Con un barrido en
    # marcha el arbol casi siempre esta sucio --por su propia mutacion-- asi
    # que al reves el segundo recibiria «tienes cambios sin guardar», que es el
    # diagnostico equivocado y manda a buscar un problema que no existe.
    #
    # Y hacia falta: la guarda de arbol sucio solo tapa esto por casualidad,
    # porque ENTRE mutaciones el arbol queda limpio. En esa ventana el segundo
    # arrancaba, y paso de verdad --dos lanzamientos cortados con `head`
    # entraron justo ahi--.
    if os.path.exists(CERROJO_RUTA):
        print("Ya hay un barrido en marcha (" + os.path.basename(CERROJO_RUTA) + ").")
        print("Dos a la vez se pisan: uno restaura lo que el otro acaba de mutar.")
        print("Si no hay ninguno corriendo, borra ese fichero.")
        return

    # NO SE ARRANCA SOBRE UN ARBOL SUCIO. Esto escribe codigo malo a proposito
    # y lo deshace con `git`: con cambios sin guardar, deshacerlos los borraria.
    sucio = arbol_sucio()
    if sucio:
        print("app/ tiene cambios sin guardar. El barrido escribe codigo malo y lo")
        print("deshace con git, asi que arrancar asi BORRARIA esos cambios:")
        print(sucio)
        return

    io.open(CERROJO_RUTA, "w", encoding="utf-8").write(str(os.getpid()) + "\n")

    desprotegidas = []
    errores = []

    for i, (etq, rel, viejo, nuevo, dano) in enumerate(MUTACIONES, 1):
        p = os.path.join(RAIZ, *rel.split("/"))
        s = leer(p)

        if s.count(viejo) != 1:
            print(f"[{i:>2}/{len(MUTACIONES)}] {etq}: NO APLICA "
                  f"(el texto aparece {s.count(viejo)} veces) -- mutacion descartada")
            sys.stdout.flush()
            continue

        escribir(p, s.replace(viejo, nuevo))
        t = time.time()
        try:
            veredicto, resumen, nombres = suite()
        finally:
            escribir(p, s)          # lo primero, y barato
            restaurar_con_git(rel)  # y que quede igual al commit, no «parecido»

        print(f"[{i:>2}/{len(MUTACIONES)}] {veredicto:<6} {etq}  ({time.time()-t:.0f}s)  {resumen}")

        if veredicto == "FALLA":
            for n in nombres:
                print(f"            cazado por: {n}")
        elif veredicto == "PASA":
            print(f"            SIN VIGILANCIA -> {dano}")
            desprotegidas.append((etq, dano))
        else:
            print("            la corrida no vale: no cuenta ni como cubierto ni como no cubierto")
            errores.append(etq)

        # El control manda: si no da lo esperado, el barrido esta ciego y se para.
        if etq.startswith("CONTROL") and veredicto != "FALLA":
            print("\n!! EL CONTROL NO DIO LO ESPERADO. El barrido esta ciego; se para aqui.")
            print("   Las respuestas de arriba no significan nada hasta arreglarlo.")
            return

        sys.stdout.flush()

    print("\n" + "=" * 70)
    if errores:
        print(f"{len(errores)} corridas invalidas (no cuentan): {', '.join(errores)}\n")
    if desprotegidas:
        print(f"{len(desprotegidas)} constantes SIN una sola prueba que las defienda:\n")
        for etq, dano in desprotegidas:
            print(f"  * {etq}\n      {dano}")
    else:
        print("Todas defendidas.")

    # Comprobacion final: el arbol quedo como estaba.
    r = subprocess.run(["git", "status", "--porcelain", "app/"],
                       cwd=RAIZ, capture_output=True, text=True, shell=True)
    sucio = (r.stdout or "").strip()
    print("\nArbol tras restaurar:", "LIMPIO" if not sucio else "SUCIO ->\n" + sucio)


if __name__ == "__main__":
    try:
        main()
    finally:
        # Se suelta SIEMPRE, incluso si algo revienta. Un `kill` no ejecuta
        # esto --igual que no ejecuta el `finally` que restaura-- y entonces el
        # cerrojo queda puesto: el mensaje de arriba dice como quitarlo.
        #
        # SOLO SI ES EL NUESTRO, y esto salio de probarlo: el proceso que se
        # NIEGA a arrancar porque ya hay otro tambien pasaba por aqui y
        # borraba el cerrojo DEL OTRO. O sea que el segundo no chocaba, pero
        # dejaba la puerta abierta a un tercero, que si chocaba con el primero.
        # Un cerrojo que lo suelta quien no lo puso no es un cerrojo.
        if mio(CERROJO_RUTA):
            os.remove(CERROJO_RUTA)
