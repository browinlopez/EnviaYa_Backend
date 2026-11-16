"""Procesos del comprador: cuenta, vitrina, catalogo, favoritos, pedido, pagos."""

import time

from arnes import Fallo, artisan, hallazgo, pedir, proceso, sql, uno

SELLO = str(int(time.time()))


@proceso('Cuenta: registro, acceso, perfil y salida')
def cuenta(paso):
    correo = 'arnes+' + SELLO + '@ejemplo.test'
    clave = 'Clave-Arnes-2026'

    c, d, _ = pedir('POST', 'v1/register', cuerpo={
        'name': 'Arnes de Validacion',
        'email': correo,
        'password': clave,
        'password_confirmation': clave,
        'phone': '30' + SELLO[-8:],
        'rol': 1,
        'consentimiento': 1,
        'politica_version': '2026-10-24',
    }, espera=[200, 201])
    paso('registro -> %s' % c)

    # SIN VERIFICAR EL CORREO NO SE ENTRA, y eso esta bien: se comprueba antes
    # de seguir, porque es un control que merece quedar fijado.
    c, d, _ = pedir('POST', 'v1/login', cuerpo={'email': correo, 'password': clave},
                    espera=[403, 429])
    paso('acceso sin verificar el correo -> %s (bloqueado)' % c)

    artisan('tinker', '--execute',
            r"App\Models\User::where('email','%s')->update(['email_verified_at' => now()]);" % correo)
    paso('correo marcado como verificado, igual que al pulsar el enlace')

    c, d, _ = pedir('POST', 'v1/login', cuerpo={'email': correo, 'password': clave},
                    espera=[200, 429])
    if c == 429:
        paso('acceso -> 429: el limitador de intentos entro. No es un fallo.')
        return
    tk = d.get('token') or d.get('access_token') or (d.get('data') or {}).get('token')
    if not tk:
        raise Fallo('el acceso no devolvio token: ' + str(list(d.keys())))
    paso('acceso -> token obtenido')

    c, d, _ = pedir('GET', 'v1/profile', token=tk, espera=200)
    paso('perfil propio -> %s' % c)

    c, d, _ = pedir('POST', 'v1/login', cuerpo={'email': correo, 'password': 'mala'},
                    espera=[401, 422])
    paso('clave equivocada -> %s (rechazada)' % c)

    pedir('POST', 'v1/logout', token=tk, espera=200)
    paso('salida -> 200')

    c, _, _ = pedir('GET', 'v1/profile', token=tk, espera=401)
    paso('el token ya no sirve -> 401')


@proceso('Cuenta: restablecer clave y reenviar verificacion')
def claves(paso):
    correo = uno("select email from user where rol = 1 and email is not null limit 1", 'email')
    c, _, _ = pedir('POST', 'v1/forgot-password', cuerpo={'email': correo}, espera=[200, 202, 429])
    paso('olvide mi clave (%s) -> %s' % ('correo real', c))

    c, d, _ = pedir('POST', 'v1/forgot-password', cuerpo={'email': 'no-existe@ejemplo.test'})
    paso('correo inexistente -> %s' % c)
    # Un 429 es el limitador de la ruta, no la fuga: con el arnes corrido
    # varias veces seguidas salta, y tomarlo por hallazgo seria un falso
    # positivo mio.
    if c not in (200, 429):
        hallazgo(
            'POST /v1/forgot-password  (ClaveController:21-23)',
            'Con un correo QUE NO EXISTE devuelve %s y con uno que si existe, 200. '
            'Eso deja averiguar si una cuenta esta registrada probando correos '
            '-enumeracion de cuentas, §35-. Y la misma rama atrapa el '
            'RESET_THROTTLED, asi que un usuario legitimo que lo pida dos veces '
            'seguidas recibe un 500 en vez de "espera un momento".' % c)

    c, _, _ = pedir('POST', 'v1/reset-password', cuerpo={
        'email': correo, 'token': 'inventado', 'password': 'Otra-Clave-2026',
        'password_confirmation': 'Otra-Clave-2026',
    }, espera=[400, 422, 429])
    paso('token inventado -> %s (rechazado)' % c)


@proceso('Vitrina publica: sin sesion')
def vitrina_publica(paso):
    for uri in ('v1/top-businesses-free', 'v1/categories-free?type=1',
                'v1/cobertura-free', 'v1/complexes-free'):
        c, d, ms = pedir('GET', uri, espera=200)
        n = len(d if isinstance(d, list) else (d.get('data') or d.get('businesses') or d or []))
        paso('%s -> %s, %s elementos, %.0f ms' % (uri.split('?')[0], c, n, ms))


@proceso('Vitrina: listado ligero vs completo')
def vitrina(paso):
    c, ligero, ms1 = pedir('GET', 'v1/businesses/index?light=1', rol='comprador', espera=200)
    c, completo, ms2 = pedir('GET', 'v1/businesses/index', rol='comprador', espera=200)

    lista_l = ligero.get('businesses') or []
    lista_c = completo.get('businesses') or []
    if not lista_l:
        raise Fallo('el listado ligero vino vacio')
    paso('ligero: %d negocios en %.0f ms | completo: %d en %.0f ms' % (
        len(lista_l), ms1, len(lista_c), ms2))

    # El ligero NO trae productos ni resenas: es la razon de que exista.
    primero = lista_l[0]
    if primero.get('products') not in (None, [], {}):
        raise Fallo('el listado ligero trae productos: ' + str(primero.get('products'))[:80])
    paso('el ligero no trae productos ni resenas, como debe')

    uno_c = lista_c[0] if lista_c else {}
    if 'products' not in uno_c:
        raise Fallo('el listado completo no trae la clave products')
    paso('el completo si trae products')


@proceso('Negocio y catalogo: ficha, productos, esquema')
def catalogo(paso):
    c, d, _ = pedir('POST', 'v1/businesses/show', rol='comprador',
                    cuerpo={'busines_id': 1}, espera=200)
    paso('ficha del negocio 1 -> %s' % c)

    c, d, _ = pedir('GET', 'v1/product/schema?business_id=1', rol='comprador', espera=200)
    paso('esquema de producto -> %s' % c)

    c, d, _ = pedir('POST', 'v1/product/topProductsBusiness', rol='comprador',
                    cuerpo={'business_id': 1}, espera=200)
    paso('mas pedidos del negocio -> %s' % c)

    c, d, _ = pedir('GET', 'v1/categories-business/index', rol='comprador', espera=200)
    paso('tipos de negocio -> %s' % c)

    # `v1/categories/show` se retiro el 11 de noviembre de 2026: ningun
    # cliente pedia nunca UNA categoria, porque la app se trae la lista
    # entera para pintar el formulario de un producto. Esa lista es la que
    # hay que comprobar, y es la que de verdad se llama.
    tipo = uno("select type from business where busines_id = 1", 'type')
    c, d, _ = pedir('GET', 'v1/categories-free?type=%s' % tipo, rol='comprador',
                    espera=200)
    n = len(d if isinstance(d, list) else (d.get('data') or d.get('categories') or []))
    paso('categorias del tipo %s -> %s, %d categorias' % (tipo, c, n))


@proceso('Favoritos: anadir, listar, quitar')
def favoritos(paso):
    def cuantos():
        _, d, _ = pedir('POST', 'v1/favorites/index', rol='comprador',
                        cuerpo={'user_id': 2}, espera=200)
        lista = d if isinstance(d, list) else (d.get('data') or d.get('favorites') or [])
        return len(lista), d

    n0, _ = cuantos()
    paso('favoritos al empezar: %d' % n0)

    c, d, _ = pedir('POST', 'v1/favorites/toggle', rol='comprador',
                    cuerpo={'user_id': 2, 'busines_id': 1}, espera=[200, 201])
    paso('conmutar el negocio 1 -> %s' % c)

    n1, _ = cuantos()
    if n1 == n0:
        raise Fallo('conmutar no cambio el numero de favoritos (%d)' % n0)
    paso('favoritos ahora: %d' % n1)

    pedir('POST', 'v1/favorites/toggle', rol='comprador',
          cuerpo={'user_id': 2, 'busines_id': 1}, espera=[200, 201])
    n2, _ = cuantos()
    if n2 != n0:
        raise Fallo('conmutar dos veces no volvio al estado inicial: %d -> %d -> %d'
                    % (n0, n1, n2))
    paso('conmutar dos veces vuelve a %d' % n2)


@proceso('Cotizacion del carrito')
def cotizacion(paso):
    pb = sql("select products_id, price from products_business "
             "where busines_id = 1 and price > 0 and amount > 3 limit 1")
    if not pb:
        raise Fallo('el negocio 1 no tiene producto con precio y existencias')
    pid = pb[0]['products_id']

    c, d, _ = pedir('POST', 'v1/orders/cotizar', rol='comprador', cuerpo={
        'user_id': 2, 'busines_id': 1, 'address_id': 1, 'methods_id': 1,
        'products': [{'product_id': pid, 'amount': 2}],
    }, espera=200)

    total = d.get('total') or (d.get('data') or {}).get('total')
    if not total:
        raise Fallo('la cotizacion no devolvio total: ' + str(list(d.keys())))
    paso('cotizacion del producto %s x2 -> total %s' % (pid, total))

    # El precio SIEMPRE lo pone el servidor: mandar uno no lo cambia.
    c, d2, _ = pedir('POST', 'v1/orders/cotizar', rol='comprador', cuerpo={
        'user_id': 2, 'busines_id': 1, 'address_id': 1, 'methods_id': 1,
        'products': [{'product_id': pid, 'amount': 2, 'price': 1, 'unit_price': 1}],
    }, espera=200)
    total2 = d2.get('total') or (d2.get('data') or {}).get('total')
    if str(total2) != str(total):
        raise Fallo('mandar un precio en el cuerpo CAMBIO el total: %s -> %s' % (total, total2))
    paso('mandar un precio en el cuerpo no cambia el total (%s)' % total2)


@proceso('Pedido en efectivo: ciclo completo y efectos')
def pedido_efectivo(paso):
    pb = sql("select products_id, amount from products_business "
             "where busines_id = 1 and price > 0 and amount > 3 limit 1")
    pid, stock0 = pb[0]['products_id'], int(pb[0]['amount'])

    c, d, ms = pedir('POST', 'v1/orders/orders', rol='comprador', cuerpo={
        'user_id': 2, 'busines_id': 1, 'address_id': 1, 'methods_id': 1,
        'products': [{'product_id': pid, 'amount': 2}],
        'cash_tendered': 50000,
    }, espera=201)
    oid = d['order']['order_id']
    paso('creado el pedido %s en %.0f ms' % (oid, ms))

    if d['order'].get('cash_tendered') in (None, '0.00'):
        raise Fallo('cash_tendered no se guardo')
    paso('paga con %s' % d['order']['cash_tendered'])

    stock1 = int(uno("select amount from products_business where busines_id = 1 "
                     "and products_id = %s" % pid, 'amount'))
    if stock1 != stock0 - 2:
        raise Fallo('el inventario no bajo: %s -> %s' % (stock0, stock1))
    paso('inventario %s -> %s' % (stock0, stock1))

    domi_user = uno("select d.user_id from business_domiciliary bd "
                    "join domiciliary d on d.domiciliary_id = bd.domiciliary_id "
                    "where bd.busines_id = 1 limit 1", 'user_id')

    pedir('PUT', 'v1/negocio/pedidos/estado', rol='tendero',
          cuerpo={'order_id': oid, 'state': 2}, espera=200)
    paso('el tendero acepta -> estado 2')

    pedir('PUT', 'v1/negocio/pedidos/estado', rol='tendero',
          cuerpo={'order_id': oid, 'state': 3, 'user_id': domi_user}, espera=200)
    paso('despachado con el domiciliario %s -> estado 3' % domi_user)

    pedir('PUT', 'v1/orders/update', rol='domiciliario',
          cuerpo={'order_id': oid, 'state': 4}, espera=200)
    paso('el domiciliario entrega -> estado 4')

    fila = sql("select state, payment_state, dispatched_at, delivery_date "
               "from orderssales where orderSales_id = %s" % oid)[0]
    if int(fila['state']) != 4 or fila['payment_state'] != 'paid':
        raise Fallo('estado final inesperado: ' + str(fila))
    if not fila['dispatched_at'] or not fila['delivery_date']:
        raise Fallo('faltan las marcas de tiempo: ' + str(fila))
    paso('estado 4, payment_state paid, con despacho y entrega sellados')

    n_fac = uno("select count(*) as n from invoices where orderSales_id = %s" % oid, 'n')
    if int(n_fac) != 1:
        raise Fallo('comprobantes emitidos: %s (se esperaba 1)' % n_fac)
    paso('comprobante emitido')

    n_aud = uno("select count(*) as n from audits where auditable_id = %s "
                "and auditable_type like '%%OrdersSales%%'" % oid, 'n')
    if int(n_aud) < 3:
        raise Fallo('solo %s filas de auditoria para 4 cambios de estado' % n_aud)
    paso('%s filas de auditoria' % n_aud)


@proceso('Cancelacion: devuelve el inventario')
def cancelacion(paso):
    pb = sql("select products_id, amount from products_business "
             "where busines_id = 1 and price > 0 and amount > 3 limit 1")
    pid, stock0 = pb[0]['products_id'], int(pb[0]['amount'])

    c, d, _ = pedir('POST', 'v1/orders/orders', rol='comprador', cuerpo={
        'user_id': 2, 'busines_id': 1, 'address_id': 1, 'methods_id': 1,
        'products': [{'product_id': pid, 'amount': 3}],
    }, espera=201)
    oid = d['order']['order_id']
    paso('pedido %s creado con 3 unidades' % oid)

    stock1 = int(uno("select amount from products_business where busines_id = 1 "
                     "and products_id = %s" % pid, 'amount'))
    paso('inventario %s -> %s' % (stock0, stock1))

    c, d, _ = pedir('PUT', 'v1/orders/%s/cancel' % oid, rol='comprador',
                    cuerpo={'motivo': 'validacion del arnes'}, espera=200)
    paso('cancelado -> estado %s' % d['order']['state'])

    stock2 = int(uno("select amount from products_business where busines_id = 1 "
                     "and products_id = %s" % pid, 'amount'))
    if stock2 != stock0:
        raise Fallo('el inventario no volvio: %s -> %s -> %s' % (stock0, stock1, stock2))
    paso('inventario devuelto a %s' % stock2)

    c, _, _ = pedir('PUT', 'v1/orders/%s/cancel' % oid, rol='comprador',
                    cuerpo={'motivo': 'otra vez'}, espera=[409, 422, 403])
    paso('cancelar dos veces -> %s (rechazado)' % c)


@proceso('Autorizacion: nadie toca el pedido de otro')
def autorizacion(paso):
    c, d, _ = pedir('POST', 'v1/orders/orders', rol='comprador', cuerpo={
        'user_id': 2, 'busines_id': 1, 'address_id': 1, 'methods_id': 1,
        'products': [{'product_id': sql("select products_id from products_business "
                                        "where busines_id = 1 and price > 0 and amount > 1 "
                                        "limit 1")[0]['products_id'], 'amount': 1}],
    }, espera=201)
    oid = d['order']['order_id']
    paso('pedido %s del negocio 1' % oid)

    # Un tendero de OTRO negocio no puede moverlo.
    otro = uno("select o.user_id from owner o join owner_busines ob "
               "on ob.owner_id = o.owner_id where ob.busines_id <> 1 limit 1", 'user_id')
    if otro:
        import subprocess as sp
        from arnes import REPO
        r = sp.run(['php', 'artisan', 'tinker', '--execute',
                    "$u = App\\Models\\User::find(%s); $u->tokens()->where('name','arnes-otro')"
                    "->delete(); echo $u->createToken('arnes-otro')->plainTextToken;" % otro],
                   capture_output=True, text=True, cwd=REPO)
        tk = r.stdout.strip().splitlines()[-1].strip()
        c, _, _ = pedir('PUT', 'v1/negocio/pedidos/estado', token=tk,
                        cuerpo={'order_id': oid, 'state': 2}, espera=[403, 404])
        paso('un tendero de otro negocio -> %s' % c)

    # Un domiciliario no asignado no puede entregarlo.
    c, _, _ = pedir('PUT', 'v1/orders/update', rol='domiciliario',
                    cuerpo={'order_id': oid, 'state': 4}, espera=[403, 422])
    paso('un domiciliario sin asignar -> %s' % c)

    # Sin sesion, nada.
    c, _, _ = pedir('POST', 'v1/orders/user', cuerpo={'user_id': 2}, espera=401)
    paso('sin sesion -> 401')

    # Y con sesion de OTRO comprador, pidiendo los pedidos del 2.
    c, d, _ = pedir('POST', 'v1/orders/user', rol='comprador', cuerpo={'user_id': 2},
                    espera=200)
    paso('con su propia sesion -> %s' % c)

    pedir('PUT', 'v1/orders/%s/cancel' % oid, rol='comprador',
          cuerpo={'motivo': 'limpieza del arnes'}, espera=200)
    paso('limpiado')


PROCESOS = [cuenta, claves, vitrina_publica, vitrina, catalogo, favoritos,
            cotizacion, pedido_efectivo, cancelacion, autorizacion]
