{{--
    COMPROBANTE DE UN PEDIDO ENTREGADO

    Media carta: es un comprobante de entrega, no un contrato. Con la escala de
    abajo caben unos veinte renglones en la hoja; un pedido más largo pasa a una
    segunda página, que es preferible a esconderle renglones a un documento que
    sirve de constancia de lo que se cobró.

    LA ESCALA IMPORTA. Estuvo en 8,5 px de cuerpo y 7 px en los rótulos, que
    sobre una hoja de 5,5 pulgadas es ilegible en pantalla —el visor la encaja al
    60 % y no se distingue el importe— y justo en el límite de lo imprimible.
    Cabía más información por hoja y no servía de nada, porque nadie podía
    leerla. Ahora el cuerpo va en 11 px: ocupa más y se lee.

    Todo sale del `snapshot`, que es la foto de los datos al emitir. Nada de
    consultas vivas: si el negocio cambió de nombre en junio, la factura de
    marzo tiene que seguir diciendo lo de marzo.

    NO dice "factura electrónica" ni lleva CUFE ni resolución de la DIAN, porque
    no lo es. Llamarlo así sería afirmar algo que no está pasando, y con la DIAN
    eso tiene consecuencias.
--}}
@php
    $pesos = fn ($v) => '$ ' . number_format((float) $v, 0, ',', '.');
    $negocio = $snapshot['negocio'] ?? [];
    $comprador = $snapshot['comprador'] ?? [];
    $entrega = $snapshot['entrega'] ?? [];
    $renglones = $snapshot['renglones'] ?? [];
@endphp
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <style>
        @page { margin: 20px 22px; }
        body {
            font-family: 'DejaVu Sans', sans-serif;
            font-size: 11px;
            color: #1f2333;
            line-height: 1.45;
        }
        .encabezado { border-bottom: 1.5px solid #1f2333; padding-bottom: 10px; }
        .marca { font-size: 17px; font-weight: bold; letter-spacing: -0.3px; }
        .numero { font-size: 16px; font-weight: bold; }
        .tenue { color: #6b7189; }
        .micro { font-size: 9px; }
        table { width: 100%; border-collapse: collapse; }
        .partes td { vertical-align: top; padding: 11px 0 0; width: 50%; }
        .rotulo {
            font-size: 8.5px; text-transform: uppercase; letter-spacing: .07em;
            color: #8a90a6; font-weight: bold; padding-bottom: 3px;
        }
        .items { margin-top: 13px; }
        .items th {
            text-align: left; font-size: 9px; text-transform: uppercase;
            letter-spacing: .06em; color: #6b7189;
            border-bottom: 1px solid #c9cde0; padding: 4px;
        }
        /* El relleno de la fila es lo que decide cuántos renglones caben en la
           hoja: cada píxel de arriba y abajo cuesta un renglón cada seis. */
        .items td { padding: 4px; border-bottom: 1px solid #eef0f7; }
        .der { text-align: right; }
        .totales { margin-top: 11px; }
        .totales td { padding: 3px 4px; }
        .total-final td {
            border-top: 1.5px solid #1f2333; font-weight: bold;
            font-size: 15px; padding-top: 6px;
        }
        .anulada {
            margin-top: 13px; padding: 8px 10px;
            border: 1.5px solid #c02626; color: #c02626;
            font-weight: bold; text-align: center;
        }
        .pie {
            margin-top: 18px; padding-top: 10px;
            border-top: 1px solid #eef0f7; color: #8a90a6;
        }
    </style>
</head>
<body>

<table class="encabezado">
    <tr>
        <td style="width:58%;">
            <div class="marca">{{ $empresa['plataforma'] ?? "VeciPa'Ya" }}</div>
            <div class="tenue micro">
                {{ $empresa['nombre'] ?? '' }}
                @if (!empty($empresa['nit'])) · NIT {{ $empresa['nit'] }} @endif
            </div>
        </td>
        <td class="der">
            <div class="rotulo">Comprobante de entrega</div>
            <div class="numero">{{ $f->invoice_number }}</div>
            <div class="tenue micro">
                {{ optional($f->invoice_date)->format('d/m/Y H:i') }}
                · Pedido #{{ $f->orderSales_id }}
            </div>
        </td>
    </tr>
</table>

<table class="partes">
    <tr>
        <td>
            <div class="rotulo">Vendido por</div>
            <div><strong>{{ $negocio['nombre'] ?? '—' }}</strong></div>
            @if (!empty($negocio['razon_social']))
                <div class="tenue micro">{{ $negocio['razon_social'] }}</div>
            @endif
            @if (!empty($negocio['nit']))
                <div class="tenue micro">NIT {{ $negocio['nit'] }}</div>
            @endif
            @if (!empty($negocio['direccion']))
                <div class="tenue micro">{{ $negocio['direccion'] }}</div>
            @endif
        </td>
        <td>
            <div class="rotulo">Entregado a</div>
            <div><strong>{{ $comprador['nombre'] ?? '—' }}</strong></div>
            @if (!empty($comprador['telefono']))
                <div class="tenue micro">{{ $comprador['telefono'] }}</div>
            @endif
            @if (!empty($comprador['entrega']))
                <div class="tenue micro">{{ $comprador['entrega'] }}</div>
            @endif
        </td>
    </tr>
</table>

<table class="items">
    <thead>
        <tr>
            <th>Producto</th>
            {{-- Anchos en px de CSS: la hoja mide 528 px de ancho (5,5 pulgadas
                 a 96 ppp) y con los márgenes quedan 484 útiles. Con la letra en
                 11 px, "$ 199.817" no cabía en las columnas de antes. --}}
            <th class="der" style="width:46px;">Cant.</th>
            <th class="der" style="width:86px;">Precio</th>
            <th class="der" style="width:95px;">Importe</th>
        </tr>
    </thead>
    <tbody>
        @forelse ($renglones as $r)
            <tr>
                <td>{{ $r['producto'] }}</td>
                <td class="der">{{ $r['cantidad'] }}</td>
                <td class="der">{{ $pesos($r['precio_unit']) }}</td>
                <td class="der">{{ $pesos($r['importe']) }}</td>
            </tr>
        @empty
            {{-- Puede pasar con pedidos viejos cuyo detalle nunca se guardó: se
                 dice, en vez de dejar la tabla en blanco como si no se hubiera
                 vendido nada. --}}
            <tr>
                <td colspan="4" class="tenue">
                    Este pedido no tiene renglones registrados.
                </td>
            </tr>
        @endforelse
    </tbody>
</table>

<table class="totales">
    <tr>
        <td style="width:62%;" class="tenue micro">
            @if (!empty($entrega['medio_pago']))
                Pago: {{ $entrega['medio_pago'] }}
                @if ($f->payment_reference) · Ref. {{ $f->payment_reference }} @endif
                <br>
            @endif
            @if (!empty($entrega['domiciliario']))
                Entregado por {{ $entrega['domiciliario'] }}
            @endif
        </td>
        <td>
            <table>
                <tr>
                    <td class="tenue">Subtotal</td>
                    <td class="der">{{ $pesos($f->subtotal) }}</td>
                </tr>
                @if ((float) $f->descuento > 0)
                    <tr>
                        <td class="tenue">Descuento</td>
                        <td class="der">− {{ $pesos($f->descuento) }}</td>
                    </tr>
                @endif
                @if ((float) $f->domicilio > 0)
                    <tr>
                        <td class="tenue">Domicilio</td>
                        <td class="der">{{ $pesos($f->domicilio) }}</td>
                    </tr>
                @endif
                <tr class="total-final">
                    <td>Total</td>
                    <td class="der">{{ $pesos($f->total) }}</td>
                </tr>
            </table>
        </td>
    </tr>
</table>

@if ($f->estaAnulada())
    {{-- Se conserva el número y el contenido: un hueco en el consecutivo es
         justo lo que un comprobante no puede tener. Lo que cambia es que el
         documento dice, sin lugar a dudas, que no vale. --}}
    <div class="anulada">
        ANULADA el {{ optional($f->voided_at)->format('d/m/Y H:i') }}
        @if ($f->void_reason) — {{ $f->void_reason }} @endif
    </div>
@endif

<div class="pie micro">
    Comprobante interno de la operación. <strong>No es una factura electrónica</strong>
    y no reemplaza la facturación ante la DIAN.
    <br>
    Los valores son los que se cobraron al entregar el pedido y no cambian
    después.
</div>

</body>
</html>
