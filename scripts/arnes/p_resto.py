"""Domiciliario, conjunto, chat, avisos, resenas, panel interno, pagos,
   comandos programados, colas, mantenimiento y respaldos."""

import json
import time

from arnes import Fallo, artisan, hallazgo, pedir, proceso, sql, uno

SELLO = str(int(time.time()))


# ------------------------------------------------------------- domiciliario

@proceso('Domiciliario: sus pedidos, efectivo, contrato y papeles')
def domiciliario(paso):
    for uri in ('v1/domiciliaries/pedidos', 'v1/domiciliaries/cash-balance',
                'v1/domiciliaries/contrato'):
        c, d, ms = pedir('GET', uri, rol='domiciliario', espera=200)
        paso('%s -> %s en %.0f ms' % (uri, c, ms))

    did = uno("select domiciliary_id from domiciliary where user_id = "
              "(select tokenable_id from personal_access_tokens where name = 'arnes' "
              " and tokenable_id in (select user_id from domiciliary) limit 1)",
              'domiciliary_id')
    if did:
        c, d, _ = pedir('POST', 'v1/domiciliaries/incomeDomiciliary', rol='domiciliario',
                        cuerpo={'domiciliary_id': did}, espera=[200, 403])
        paso('ingresos (lo que la app ya no pide) -> %s' % c)


@proceso('Domiciliario: reporta su ubicacion y se lee la ultima')
def ubicacion(paso):
    did = uno("select bd.domiciliary_id from business_domiciliary bd "
              "where bd.busines_id = 1 limit 1", 'domiciliary_id')

    c, d, _ = pedir('POST', 'v1/orders/geolocation', rol='domiciliario', cuerpo={
        'domiciliary_id': did, 'latitude': 10.9891, 'longitude': -74.8431,
    }, espera=[200, 201, 422])
    paso('guardar posicion -> %s' % c)

    c, d, _ = pedir('GET', 'v1/orders/geolocation/latest?domiciliary_id=%s' % did,
                    rol='comprador', espera=[200, 404])
    paso('ultima posicion -> %s' % c)

    n = uno("select count(*) as n from domiciliary_locations where domiciliary_id = %s"
            % did, 'n') if sql("select count(*) as n from information_schema.tables "
                               "where table_schema = database() and table_name = "
                               "'domiciliary_locations'")[0]['n'] else None
    if n is not None:
        paso('posiciones guardadas de ese domiciliario: %s' % n)


@proceso('Codigo de porteria del domiciliario')
def porteria(paso):
    c, d, _ = pedir('POST', 'v1/domiciliaries/access-code', rol='domiciliario',
                    cuerpo={}, espera=[200, 201, 404, 422])
    paso('pedir codigo -> %s' % c)


# ------------------------------------------------------------------ chat

@proceso('Chat: crear, mandar, leer y el tope de mensajes')
def chat(paso):
    c, d, _ = pedir('POST', 'v1/chats/user-chats', rol='comprador',
                    cuerpo={'user_id': 2}, espera=200)
    paso('mis conversaciones -> %s' % c)

    tendero_uid = uno("select o.user_id from owner o join owner_busines ob "
                      "on ob.owner_id = o.owner_id where ob.busines_id = 1 limit 1",
                      'user_id')
    c, d, _ = pedir('POST', 'v1/chats/create', rol='comprador', cuerpo={
        'type': 'private',
        'participants': [{'user_id': 2, 'role_id': 1},
                         {'user_id': tendero_uid, 'role_id': 2}],
    }, espera=[200, 201])
    chat_id = ((d.get('chat') or d.get('data') or d) or {}).get('chat_id')
    paso('crear/recuperar conversacion -> %s (id %s)' % (c, chat_id))
    if not chat_id:
        raise Fallo('no devolvio chat_id: ' + str(d)[:150])

    c, d, _ = pedir('POST', 'v1/chats/send-message', rol='comprador', cuerpo={
        'chat_id': chat_id, 'user_id': 2, 'recipient_id': tendero_uid,
        'content': 'mensaje del arnes ' + SELLO,
    }, espera=[200, 201])
    paso('mandar mensaje -> %s' % c)

    c, d, _ = pedir('POST', 'v1/chats/messages', rol='comprador',
                    cuerpo={'chat_id': chat_id}, espera=200)
    msgs = d.get('data') or d.get('messages') or (d if isinstance(d, list) else [])
    paso('leer -> %s, %d mensajes' % (c, len(msgs)))

    largo = 'x' * 4000
    c, d, _ = pedir('POST', 'v1/chats/send-message', rol='comprador', cuerpo={
        'chat_id': chat_id, 'user_id': 2, 'recipient_id': tendero_uid,
        'content': largo,
    })
    if c == 422:
        paso('un mensaje de 4000 caracteres -> 422 (hay tope)')
    else:
        paso('un mensaje de 4000 caracteres -> %s' % c)


# ------------------------------------------------------- avisos y resenas

@proceso('Avisos: registrar dispositivo, listar y marcar leido')
def avisos(paso):
    c, d, _ = pedir('POST', 'v1/devices', rol='comprador', cuerpo={
        'token': 'ExponentPushToken[arnes-' + SELLO + ']',
        'platform': 'android',
    }, espera=[200, 201, 422])
    paso('registrar dispositivo -> %s' % c)

    c, d, _ = pedir('GET', 'v1/users/notifications', rol='comprador', espera=[200, 404])
    paso('mis avisos -> %s' % c)

    c, d, _ = pedir('PUT', 'v1/users/notifications/read', rol='comprador',
                    cuerpo={}, espera=[200, 204, 422])
    paso('marcar leidos -> %s' % c)

    c, _, _ = pedir('DELETE', 'v1/devices', rol='comprador', cuerpo={
        'token': 'ExponentPushToken[arnes-' + SELLO + ']',
    }, espera=[200, 204, 404, 422])
    paso('quitar dispositivo -> %s' % c)


@proceso('Resenas de negocio')
def resenas(paso):
    c, d, _ = pedir('GET', 'v1/reviews/business/all', rol='comprador', espera=200)
    paso('todas -> %s' % c)

    c, d, _ = pedir('POST', 'v1/reviews/business/by', rol='comprador',
                    cuerpo={'busines_id': 1}, espera=[200, 422])
    paso('las del negocio 1 -> %s' % c)


# --------------------------------------------------------------- conjunto

@proceso('Conjunto: su panel, personal y visitantes')
def conjunto(paso):
    for uri in ('v1/conjunto/me', 'v1/conjunto/resumen', 'v1/conjunto/celadores',
                'v1/conjunto/residentes', 'v1/conjunto/reportes',
                'v1/conjunto/porteria/adentro', 'v1/conjunto/porteria/entradas'):
        c, d, ms = pedir('GET', uri, rol='conjunto')
        if c >= 500:
            raise Fallo('%s -> %s: %s' % (uri, c, json.dumps(d)[:180]))
        paso('%s -> %s en %.0f ms' % (uri.replace('v1/conjunto/', ''), c, ms))

    c, d, _ = pedir('POST', 'v1/conjunto/porteria/visitantes', rol='conjunto', cuerpo={
        'nombre': 'Visitante del arnes', 'documento': '1' + SELLO[-9:],
        'tower': '3', 'apartment': '502', 'motivo': 'validacion',
    })
    if c >= 500:
        raise Fallo('registrar visitante -> %s: %s' % (c, json.dumps(d)[:200]))
    paso('registrar visitante -> %s' % c)

    c, d, _ = pedir('POST', 'v1/conjunto/porteria/verificar', rol='conjunto',
                    cuerpo={'codigo': 'NO-EXISTE'})
    if c >= 500:
        raise Fallo('verificar codigo -> %s: %s' % (c, json.dumps(d)[:200]))
    paso('verificar un codigo inventado -> %s' % c)


# ----------------------------------------------------------- panel interno

@proceso('Panel interno: resumen, listados y modulos por area')
def panel(paso):
    for uri in ('v1/admin/overview', 'v1/admin/me', 'v1/admin/users', 'v1/admin/businesses',
                'v1/admin/orders', 'v1/admin/payments', 'v1/admin/domiciliaries',
                'v1/admin/settlements', 'v1/admin/invoices', 'v1/admin/pqrs',
                'v1/admin/solicitudes', 'v1/admin/audits', 'v1/admin/settings',
                'v1/admin/areas', 'v1/admin/security/modules', 'v1/admin/locations',
                'v1/admin/cash-balances', 'v1/admin/cash-deposits', 'v1/admin/storage',
                'v1/admin/complexes', 'v1/admin/categories', 'v1/admin/owners',
                'v1/admin/products', 'v1/admin/reviews', 'v1/admin/fallos',
                'v1/admin/chats'):
        c, d, ms = pedir('GET', uri, rol='admin')
        if c >= 500:
            raise Fallo('%s -> %s: %s' % (uri, c, json.dumps(d)[:200]))
        paso('%s -> %s en %.0f ms' % (uri.replace('v1/admin/', ''), c, ms))


@proceso('Panel interno: los informes, uno por uno')
def informes(paso):
    for clase in ('ventas', 'operacional', 'domiciliarios', 'negocios', 'productos',
                  'clientes', 'pagos'):
        c, d, ms = pedir('GET', 'v1/admin/reports/%s' % clase, rol='admin')
        if c >= 500:
            raise Fallo('informe %s -> %s: %s' % (clase, c, json.dumps(d)[:180]))
        paso('%s -> %s en %.0f ms' % (clase, c, ms))


@proceso('Panel interno: marketing completo')
def marketing(paso):
    for uri in ('v1/admin/marketing/banners', 'v1/admin/marketing/campaigns',
                'v1/admin/marketing/coupons', 'v1/admin/marketing/advertisers',
                'v1/admin/marketing/featured', 'v1/admin/marketing/push'):
        c, d, ms = pedir('GET', uri, rol='admin')
        if c >= 500:
            raise Fallo('%s -> %s: %s' % (uri, c, json.dumps(d)[:180]))
        paso('%s -> %s en %.0f ms' % (uri.split('/')[-1], c, ms))

    codigo = 'ARNES' + SELLO[-6:]
    c, d, _ = pedir('POST', 'v1/admin/marketing/coupons', rol='admin', cuerpo={
        'code': codigo, 'type': 'percent', 'value': 5,
        'starts_at': time.strftime('%Y-%m-%d'),
        'ends_at': time.strftime('%Y-%m-%d', time.localtime(time.time() + 86400)),
    })
    if c not in (200, 201):
        raise Fallo('crear cupon -> %s: %s' % (c, json.dumps(d)[:200]))
    cid = d.get('id')
    paso('cupon %s creado (id %s)' % (codigo, cid))

    c, _, _ = pedir('DELETE', 'v1/admin/marketing/coupons/%s' % cid, rol='admin',
                    espera=[200, 204])
    paso('cupon borrado -> %s' % c)


@proceso('Autorizacion del panel: un tendero no entra')
def panel_cerrado(paso):
    for uri in ('v1/admin/overview', 'v1/admin/users', 'v1/admin/settings'):
        c, _, _ = pedir('GET', uri, rol='tendero', espera=[401, 403])
        paso('%s con sesion de tendero -> %s' % (uri.replace('v1/admin/', ''), c))

    for uri in ('v1/admin/overview',):
        c, _, _ = pedir('GET', uri, espera=401)
        paso('%s sin sesion -> %s' % (uri.replace('v1/admin/', ''), c))


# ------------------------------------------------------------------ pagos

@proceso('Pasarela Bold: bancos, intencion y consulta de estado')
def bold(paso):
    c, d, ms = pedir('GET', 'v1/pse/banks', rol='comprador')
    if c >= 500:
        raise Fallo('bancos PSE -> %s: %s' % (c, json.dumps(d)[:200]))
    n = len(d if isinstance(d, list) else (d.get('data') or d.get('banks') or []))
    paso('bancos PSE -> %s, %s bancos, %.0f ms' % (c, n, ms))

    c, d, ms = pedir('GET', 'v1/paymentForms', rol='comprador', espera=200)
    paso('formas de pago -> %s en %.0f ms' % (c, ms))

    c, d, ms = pedir('GET', 'v1/paymentMethods', rol='comprador', espera=200)
    paso('metodos de pago -> %s en %.0f ms' % (c, ms))

    c, d, ms = pedir('GET', 'v1/bold/status/NO-EXISTE-' + SELLO, rol='comprador')
    paso('estado de una referencia inventada -> %s en %.0f ms' % (c, ms))
    if c >= 500:
        hallazgo('GET /v1/bold/status/{ref}',
                 'Una referencia que no existe en la pasarela devuelve %s con el cuerpo '
                 'CRUDO de Bold dentro de `message` ("PA_004 Reference Error"). Es un '
                 'error del cliente, no del servidor: deberia ser 404, y el cuerpo de '
                 'la pasarela no deberia salir hacia el telefono.' % c)


@proceso('Webhook de Bold: firmado entra, sin firmar no')
def webhook(paso):
    cod, salida = artisan('bold:comprobar')
    paso('diagnostico bold:comprobar -> salida %s' % cod)

    c, d, _ = pedir('POST', 'v1/webhooks/bold', cuerpo={
        'type': 'SALE_APPROVED',
        'data': {'payment_id': 'ARNES-' + SELLO, 'metadata': {'reference': 'X'}},
    })
    if c in (200, 202):
        hallazgo('POST /v1/webhooks/bold',
                 'Acepto un aviso SIN FIRMA (%s). Con el secreto configurado tiene que '
                 'rechazarlo: un aviso sin firmar lo puede mandar cualquiera y marca '
                 'pedidos como pagados.' % c)
        paso('aviso sin firma -> %s  <-- MIRAR' % c)
    else:
        paso('aviso sin firma -> %s (rechazado, correcto)' % c)

    cod, salida = artisan('bold:comprobar', '--simular')
    paso('simulacion firmada de punta a punta -> salida %s' % cod)
    if cod != 0:
        hallazgo('bold:comprobar --simular',
                 'La simulacion de un aviso FIRMADO no termina bien (salida %s). '
                 'Ultimas lineas: %s' % (cod, ' | '.join(salida.strip().splitlines()[-3:])))


# --------------------------------------------------- comandos programados

@proceso('Comandos programados: los siete del cron')
def programados(paso):
    cod, salida = artisan('schedule:list')
    if cod != 0:
        raise Fallo('schedule:list fallo: ' + salida[-200:])
    n = len([l for l in salida.splitlines() if 'artisan' in l])
    paso('el planificador declara %d tareas' % n)

    for cmd in (('resumen:diario',), ('liquidaciones:diarias',), ('auditoria:podar',),
                ('ubicaciones:podar',), ('backup:clean',), ('backup:monitor',)):
        cod, salida = artisan(*cmd)
        ultimas = ' | '.join([l.strip() for l in salida.strip().splitlines() if l.strip()][-2:])
        if cod != 0:
            hallazgo('php artisan %s' % cmd[0],
                     'Termina con salida %s. Esta en el cron, asi que falla cada dia en '
                     'silencio. Ultimo: %s' % (cod, ultimas[:220]))
            paso('%s -> salida %s  <-- MIRAR' % (cmd[0], cod))
        else:
            paso('%s -> salida 0 (%s)' % (cmd[0], ultimas[:90]))


@proceso('Copia de seguridad: se hace y se vigila')
def respaldo(paso):
    cod, salida = artisan('backup:run', '--only-db', '--disable-notifications')
    ultimas = ' | '.join([l.strip() for l in salida.strip().splitlines() if l.strip()][-3:])
    if cod != 0:
        hallazgo('php artisan backup:run',
                 'La copia de seguridad FALLA (salida %s): %s' % (cod, ultimas[:250]))
        paso('backup:run -> salida %s  <-- MIRAR' % cod)
        return
    paso('backup:run -> salida 0 (%s)' % ultimas[:120])

    cod, salida = artisan('backup:monitor')
    paso('backup:monitor -> salida %s' % cod)


# ------------------------------------------------------------ mantenimiento

@proceso('Modo mantenimiento: para la app y deja entrar al panel')
def mantenimiento(paso):
    c, d, _ = pedir('GET', 'v1/app/config', espera=200)
    paso('config de la app -> %s' % c)

    c, d, _ = pedir('GET', 'v1/admin/settings', rol='admin', espera=200)
    paso('ajustes del panel -> %s' % c)

    # No se enciende el mantenimiento: dejaria la API caida si algo falla a
    # mitad, y el interruptor ya tiene sus propias pruebas en la suite.
    paso('el interruptor NO se acciona aqui: lo cubre MantenimientoTest y '
         'encenderlo a medias dejaria la API parada')


PROCESOS = [domiciliario, ubicacion, porteria, chat, avisos, resenas, conjunto,
            panel, informes, marketing, panel_cerrado, bold, webhook,
            programados, respaldo, mantenimiento]
