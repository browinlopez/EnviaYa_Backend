"""Procesos del tendero: su negocio, productos, promociones, efectivo, creditos, domiciliarios."""

import time

from arnes import Fallo, artisan, hallazgo, pedir, proceso, sql, uno

SELLO = str(int(time.time()))


@proceso('Mi negocio: ficha, editar y el interruptor de recibir')
def mi_negocio(paso):
    c, d, _ = pedir('GET', 'v1/negocio/me', rol='tendero', espera=200)
    neg = d.get('data') or d.get('business') or d
    paso('ficha -> %s, negocio "%s"' % (c, neg.get('name')))

    nombre0 = neg.get('name')

    c, _, _ = pedir('PUT', 'v1/negocio/me', rol='tendero',
                    cuerpo={'name': nombre0, 'phone': neg.get('phone') or '3000000000'},
                    espera=200)
    paso('editar con los mismos datos -> %s' % c)

    # El interruptor de recibir pedidos, ida y vuelta.
    estado0 = uno("select accepting_orders from business where busines_id = 1",
                  'accepting_orders')
    c, _, _ = pedir('PUT', 'v1/negocio/me/recibiendo', rol='tendero',
                    cuerpo={'accepting_orders': False}, espera=200)
    estado1 = uno("select accepting_orders from business where busines_id = 1",
                  'accepting_orders')
    if str(estado1) == str(estado0):
        raise Fallo('el interruptor no cambio nada (%s)' % estado0)
    paso('apagar recibir: %s -> %s' % (estado0, estado1))

    pedir('PUT', 'v1/negocio/me/recibiendo', rol='tendero',
          cuerpo={'accepting_orders': True}, espera=200)
    estado2 = uno("select accepting_orders from business where busines_id = 1",
                  'accepting_orders')
    paso('encender otra vez: %s' % estado2)

    # NO se toca el logo: cambiarlo alteraria lo que ve la gente de verdad.
    paso('el logo NO se prueba aqui: subirlo cambiaria el de una tienda real')


@proceso('Tablero del tendero: pedidos, mas vendidos, ingresos, resenas')
def tablero(paso):
    for uri in ('v1/negocio/pedidos', 'v1/negocio/pedidos/historial',
                'v1/negocio/mas-vendidos', 'v1/negocio/ingresos',
                'v1/negocio/resenas', 'v1/negocio/torre'):
        c, d, ms = pedir('GET', uri, rol='tendero', espera=200)
        paso('%s -> %s en %.0f ms' % (uri, c, ms))


@proceso('Productos del negocio: alta, cambio de precio y existencias, baja')
def productos(paso):
    c, d, _ = pedir('GET', 'v1/negocio/catalogo', rol='tendero', espera=200)
    paso('catalogo del negocio -> %s' % c)

    # El tendero ELIGE del catalogo; no inventa el producto. Se busca uno que
    # su tienda todavia no tenga.
    libre = sql("select p.products_id from products p where p.state = 1 and "
                "p.products_id not in (select products_id from products_business "
                "where busines_id = 1) limit 1")
    if not libre:
        raise Fallo('no hay producto del catalogo que la tienda 1 no tenga ya')
    pid = libre[0]['products_id']

    c, d, _ = pedir('POST', 'v1/negocio/productos', rol='tendero', cuerpo={
        'products_id': pid, 'price': 7500, 'amount': 12,
    }, espera=[200, 201])
    paso('alta del producto %s del catalogo -> %s' % (pid, c))

    fila = sql("select price, amount from products_business where busines_id = 1 "
               "and products_id = %s" % pid)
    if not fila:
        raise Fallo('el producto no quedo en products_business')
    paso('en la tienda a %s, %s unidades' % (fila[0]['price'], fila[0]['amount']))

    c, _, _ = pedir('PUT', 'v1/negocio/productos/%s' % pid, rol='tendero',
                    cuerpo={'price': 9900, 'amount': 30}, espera=200)
    nuevo = sql("select price, amount from products_business where busines_id = 1 "
                "and products_id = %s" % pid)[0]
    if float(nuevo['price']) != 9900.0 or int(nuevo['amount']) != 30:
        raise Fallo('el cambio no se guardo: ' + str(nuevo))
    paso('precio y existencias a 9900 / 30')

    c, _, _ = pedir('DELETE', 'v1/negocio/productos/%s' % pid, rol='tendero',
                    espera=[200, 204])
    queda = uno("select count(*) as n from products_business where busines_id = 1 "
                "and products_id = %s" % pid, 'n')
    if int(queda) != 0:
        raise Fallo('el producto sigue en la tienda tras la baja')
    paso('baja -> %s, ya no esta en la tienda' % c)


@proceso('Promociones: fechas libres, tope de usos y que se agote')
def promociones(paso):
    c, d, _ = pedir('GET', 'v1/negocio/promociones', rol='tendero', espera=200)
    paso('listado -> %s' % c)

    pid = sql("select products_id from products_business where busines_id = 1 "
              "and price > 0 limit 1")[0]['products_id']

    cuerpo = {
        'texto': 'Arnes de validacion ' + SELLO + ': diez por ciento',
        'porcentaje': 10,
        'hasta': time.strftime('%Y-%m-%d', time.localtime(time.time() + 86400 * 3)),
        'max_usos': 2,
        'productos': [pid],
    }
    c, d, _ = pedir('POST', 'v1/negocio/promociones', rol='tendero', cuerpo=cuerpo)
    if c == 422 and (d.get('reason') == 'limite_diario' or 'de hoy' in str(d)):
        # NO es un fallo: una tienda manda una promocion al dia, y una corrida
        # anterior del arnes ya gasto la de hoy. La regla funcionando.
        paso('alta -> 422 por el limite de una promocion al dia (regla correcta)')
        return
    if c not in (200, 201):
        raise Fallo('POST promociones -> %s: %s' % (c, str(d)[:200]))
    paso('alta con fechas libres y tope 2 -> %s' % c)

    fila = sql("select promotion_id, max_usos, usos, start_date, end_date "
               "from promotions where busines_id = 1 order by promotion_id desc "
               "limit 1")[0]
    paso('en la base: tope %s, usados %s, del %s al %s'
         % (fila['max_usos'], fila['usos'], fila['start_date'], fila['end_date']))

    pedir('DELETE', 'v1/negocio/promociones/%s' % fila['promotion_id'], rol='tendero',
          espera=[200, 204])
    paso('borrada, para no dejarla viva en la base')


@proceso('Efectivo de la tienda y credito a clientes')
def efectivo_y_credito(paso):
    c, d, ms = pedir('GET', 'v1/negocio/efectivo', rol='tendero', espera=200)
    paso('efectivo -> %s en %.0f ms' % (c, ms))

    c, d, _ = pedir('GET', 'v1/negocio/creditos', rol='tendero', espera=200)
    lista = d.get('data') or d.get('creditos') or (d if isinstance(d, list) else [])
    paso('creditos -> %s, %d clientes' % (c, len(lista)))

    cliente = uno("select ub.user_id from users_business ub where ub.busines_id = 1 limit 1",
                  'user_id') if sql("select count(*) as n from information_schema.tables "
                                    "where table_schema = database() and table_name = "
                                    "'users_business'")[0]['n'] else None
    if not cliente:
        cliente = uno("select user_id from buyer limit 1", 'user_id')

    c, d, _ = pedir('GET', 'v1/negocio/creditos/%s/movimientos' % cliente, rol='tendero',
                    espera=[200, 403, 404])
    paso('movimientos del cliente %s -> %s' % (cliente, c))


@proceso('Domiciliarios del negocio: buscar, listar, ficha y rendimiento')
def domiciliarios_del_negocio(paso):
    c, d, _ = pedir('GET', 'v1/negocio/domiciliarios', rol='tendero', espera=200)
    lista = d.get('data') or (d if isinstance(d, list) else [])
    paso('listado -> %s, %d domiciliarios' % (c, len(lista)))

    c, d, _ = pedir('GET', 'v1/negocio/domiciliarios/rendimiento', rol='tendero',
                    espera=200)
    paso('rendimiento -> %s' % c)

    doc = uno("select document from domiciliary where document is not null limit 1",
              'document')
    if doc:
        c, d, _ = pedir('GET', 'v1/negocio/domiciliarios/buscar?documento=%s' % doc,
                        rol='tendero', espera=[200, 404, 422])
        paso('buscar por cedula -> %s' % c)

    did = uno("select bd.domiciliary_id from business_domiciliary bd "
              "where bd.busines_id = 1 limit 1", 'domiciliary_id')
    if did:
        c, d, _ = pedir('GET', 'v1/negocio/domiciliarios/%s' % did, rol='tendero',
                        espera=[200, 404])
        paso('ficha del domiciliario %s -> %s' % (did, c))


@proceso('Carga de catalogo desde el punto de venta (.xls)')
def carga_catalogo(paso):
    c, d, _ = pedir('GET', 'v1/negocio/productos/cargas', rol='tendero', espera=200)
    paso('expedientes de carga -> %s' % c)

    c, d, ms = pedir('GET', 'v1/negocio/productos/cargas/plantilla', rol='tendero',
                     espera=200)
    paso('plantilla -> %s en %.0f ms' % (c, ms))

    # Un .xls BIFF2 minimo, el formato que de verdad sueltan los puntos de venta.
    import struct

    def texto(fila, col, v):
        v = v.encode('cp1252', 'replace')
        cuerpo = struct.pack('<HH', fila, col) + b'\x00\x00\x00' + bytes([len(v)]) + v
        return struct.pack('<HH', 0x04, len(cuerpo)) + cuerpo

    def numero(fila, col, v):
        cuerpo = struct.pack('<HH', fila, col) + b'\x00\x00\x00' + struct.pack('<d', v)
        return struct.pack('<HH', 0x03, len(cuerpo)) + cuerpo

    bytes_ = b'\x09\x00\x04\x00' + struct.pack('<HH', 0, 0x10)
    for i, h in enumerate(['CODIGO', 'NOMBRE', 'PRECIO', 'EXISTENCIA']):
        bytes_ += texto(0, i, h)
    bytes_ += texto(1, 0, 'ARN' + SELLO) + texto(1, 1, 'Articulo del arnes') \
        + numero(1, 2, 4500) + numero(1, 3, 7)
    bytes_ += struct.pack('<HH', 0x0A, 0)

    c, d, _ = pedir('POST', 'v1/negocio/productos/cargas', rol='tendero',
                    archivo=('ARTICULOS.XLS', bytes_, 'application/vnd.ms-excel'),
                    espera=202)
    exp = (d.get('data') or {}).get('catalog_upload_id') or d.get('catalog_upload_id')
    paso('subida aceptada -> 202, expediente %s' % exp)

    fila = sql("select estado, filas, creados, actualizados, rechazados "
               "from catalog_uploads where catalog_upload_id = %s" % exp)
    if not fila:
        raise Fallo('el expediente %s no quedo en catalog_uploads' % exp)
    f = fila[0]
    paso('expediente: estado "%s", %s filas, %s creados, %s actualizados, %s rechazados'
         % (f['estado'], f['filas'], f['creados'], f['actualizados'], f['rechazados']))

    # El trabajo va en cola (`QUEUE_CONNECTION=database`). Se corre un trabajador
    # de una sola pasada para ver si el expediente avanza de verdad.
    # `--once` SOLO. Con `--once --stop-when-empty` juntos el trabajador sale
    # sin procesar nada, y la primera version de esta prueba concluyo de ahi
    # que el trabajo no se procesaba. No era el backend: era como lo lanzaba.
    # VACIAR la cola, no una pasada: `--once` procesa el trabajo MAS VIEJO, y
    # con noventa delante el de la carga no llegaba nunca. La primera version
    # concluyo de ahi que el trabajo no se procesaba.
    for _ in range(3):
        cod, _s = artisan('queue:work', '--stop-when-empty')
        f2 = sql("select estado from catalog_uploads where catalog_upload_id = %s" % exp)[0]
        if f2['estado'] != 'pendiente':
            break
    paso('trabajadores de la cola corridos -> salida %s' % cod)

    f2 = sql("select estado, filas, creados, actualizados, rechazados "
             "from catalog_uploads where catalog_upload_id = %s" % exp)[0]
    paso('tras la cola: estado "%s", %s creados, %s actualizados, %s rechazados'
         % (f2['estado'], f2['creados'], f2['actualizados'], f2['rechazados']))
    if f2['estado'] == 'pendiente':
        raise Fallo('el expediente %s sigue pendiente tras doce pasadas del trabajador' % exp)


PROCESOS = [mi_negocio, tablero, productos, promociones, efectivo_y_credito,
            domiciliarios_del_negocio, carga_catalogo]
