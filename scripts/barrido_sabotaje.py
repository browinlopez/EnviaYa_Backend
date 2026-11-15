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
import re
import subprocess
import sys
import time

RAIZ = r"C:\Users\Usuario\Documents\GitHub\Desarrollo EnviaYa\EnviaYa_Backend\EnviaYa_Backend"

# (etiqueta, fichero, texto original, texto saboteado, que error representa)
#
# LAS DOS PRIMERAS SON EL CONTROL, y van primero a proposito: la respuesta de
# las dos se conoce de antemano. `ENTREGADO` esta defendido --saboteado el 14 de
# noviembre tumbo 18 pruebas-- y `COBRADOS` no lo estaba --con `['rejected']`
# pasaban las 1356--. Si el control sale al reves, el barrido esta ciego y sus
# demas respuestas no valen nada: un detector que no encuentra lo que SE que
# esta pasa en verde sin mirar.
MUTACIONES = [
    ("CONTROL (debe FALLAR): OrdersSales::ENTREGADO", "app/Models/Order/OrdersSales.php",
     "public const ENTREGADO = 4;",
     "public const ENTREGADO = 3;",
     "entregado y en camino se confunden -- defendido, comprobado el 14 de noviembre"),

    ("CONTROL (debe FALLAR): Payment::COBRADOS", "app/Models/Payment/Payment.php",
     "public const COBRADOS = ['approved', 'paid'];",
     "public const COBRADOS = ['approved', 'rejected'];",
     "un pago rechazado cuenta como cobrado -- se arreglo hoy, asi que YA debe estar defendido"),

    # ---- DINERO: el efectivo de la operacion ----
    ("CashDeposit::CONFIRMADA", "app/Models/Operacion/CashDeposit.php",
     "public const CONFIRMADA = 'confirmada';",
     "public const CONFIRMADA = 'pendiente';",
     "una entrega PENDIENTE cuenta como confirmada: plata que nadie entrego, dada por recibida"),

    ("CashDeposit::MOSTRADOR", "app/Models/Operacion/CashDeposit.php",
     "public const MOSTRADOR = 'mostrador';",
     "public const MOSTRADOR = 'consignacion';",
     "el canal se confunde: lo entregado en el mostrador se contabiliza como consignacion al banco"),

    ("CashMovement::RECAUDO", "app/Models/Operacion/CashMovement.php",
     "public const RECAUDO = 'recaudo';",
     "public const RECAUDO = 'ajuste';",
     "lo cobrado al cliente entra al libro como un ajuste a mano"),

    ("CashMovement::CONSIGNACION", "app/Models/Operacion/CashMovement.php",
     "public const CONSIGNACION = 'consignacion';",
     "public const CONSIGNACION = 'recaudo';",
     "consignar al banco SUMA al saldo del domiciliario en vez de restarlo"),

    # ---- DINERO: los descuentos ----
    ("Coupon::TIPO_PORCENTAJE", "app/Models/Marketing/Coupon.php",
     "public const TIPO_PORCENTAJE = 'percent';",
     "public const TIPO_PORCENTAJE = 'fixed';",
     "un cupon del 10% se aplica como 10 pesos fijos, o al reves"),

    ("Coupon::TIPO_ENVIO_GRATIS", "app/Models/Marketing/Coupon.php",
     "public const TIPO_ENVIO_GRATIS = 'free_shipping';",
     "public const TIPO_ENVIO_GRATIS = 'fixed';",
     "el envio gratis deja de reconocerse y se trata como descuento fijo"),

    # ---- DINERO: el estado del pago del pedido ----
    ("OrdersSales::A_CREDITO", "app/Models/Order/OrdersSales.php",
     "public const A_CREDITO = 'store_credit';",
     "public const A_CREDITO = 'pending_online';",
     "el pedido a credito de la tienda se confunde con uno esperando pasarela: no entra a liquidacion"),

    ("OrdersSales::SIN_PAGO", "app/Models/Order/OrdersSales.php",
     "public const SIN_PAGO = [self::ESPERANDO_PAGO, self::PAGO_RECHAZADO];",
     "public const SIN_PAGO = [self::ESPERANDO_PAGO, self::PAGO_RECHAZADO, self::A_CREDITO];",
     "los pedidos a credito dejan de ser pedidos: desaparecen de las cuentas de la tienda"),

    ("OrdersSales::ESPERANDO_PAGO", "app/Models/Order/OrdersSales.php",
     "public const ESPERANDO_PAGO = 'pending_online';",
     "public const ESPERANDO_PAGO = 'approved';",
     "un pago APROBADO se lee como pendiente: el pedido no es un pedido"),

    # ---- PERMISOS: quien puede escribir en el panel ----
    ("Area::NIVEL_GESTOR", "app/Models/Area.php",
     "public const NIVEL_GESTOR = 'gestor';",
     "public const NIVEL_GESTOR = 'consulta';",
     "gestor y consulta se vuelven indistinguibles: quien solo mira, escribe"),

    # ---- PERMISOS: la porteria del conjunto ----
    ("ComplexStaff::ROL_CELADOR", "app/Models/Conjunto/ComplexStaff.php",
     "public const ROL_CELADOR = 6;",
     "public const ROL_CELADOR = 5;",
     "el celador pasa por dueno del conjunto"),

    ("ComplexStaff::CELADOR", "app/Models/Conjunto/ComplexStaff.php",
     "public const CELADOR = 'celador';",
     "public const CELADOR = 'dueno';",
     "el cargo del celador se guarda y se lee como dueno"),

    # ---- DINERO: los papeles obligatorios del domiciliario ----
    ("DomiciliaryDocument::OBLIGATORIOS", "app/Models/Operacion/DomiciliaryDocument.php",
     "public const OBLIGATORIOS = ['licencia', 'soat', 'tecnomecanica', 'arl', 'eps'];",
     "public const OBLIGATORIOS = ['licencia'];",
     "cuatro de los cinco papeles dejan de ser obligatorios: sale a repartir sin SOAT ni ARL"),
]

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

def main():
    print("BARRIDO DE SABOTAJE -", len(MUTACIONES), "mutaciones\n")
    print("FALLA = defendido.  PASA = nadie lo vigila.\n")

    # NO SE ARRANCA SOBRE UN ARBOL SUCIO. Esto escribe codigo malo a proposito
    # y lo deshace con `git`: con cambios sin guardar, deshacerlos los borraria.
    sucio = arbol_sucio()
    if sucio:
        print("app/ tiene cambios sin guardar. El barrido escribe codigo malo y lo")
        print("deshace con git, asi que arrancar asi BORRARIA esos cambios:")
        print(sucio)
        return

    desprotegidas = []
    errores = []

    for i, (etq, rel, viejo, nuevo, dano) in enumerate(MUTACIONES, 1):
        p = RAIZ + "\\" + rel.replace("/", "\\")
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
    main()
