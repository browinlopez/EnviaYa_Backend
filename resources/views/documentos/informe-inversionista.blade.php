{{--
    INFORME PARA INVERSIONISTAS, EN PDF

    Carta vertical. Las mismas cifras del Excel y del panel —salen de
    `InformeDelInversionista`, que se las pide a los servicios de cada módulo—
    en el orden en que se leen en una junta: la portada con las cifras clave,
    después negocios, ciudades, usuarios, operación e ingresos, y al final la
    serie de doce meses.

    EL MEMBRETE se repite en cada página (los `position: fixed` de dompdf van
    en todas): arriba el logo de VeciPa'Ya, el título, el periodo y la fecha en
    que se generó; abajo «Solo cifras agregadas» y la página. El logo va como
    `data:` porque el documento se arma sin red (`isRemoteEnabled` apagado).

    LAS BURBUJAS del fondo son las del panel (`burbujas-fondo`): círculos de
    color muy suaves detrás de todo, para que el papel se sienta de la marca
    sin pelear con los números. Solo en el PDF.

    Solo agregados: no hay un nombre, un teléfono ni un pedido suelto.
--}}
@php
    use Carbon\Carbon;

    $pesos = fn ($v) => $v === null ? '—' : '$ ' . number_format((float) $v, 0, ',', '.');
    $num = fn ($v, $dec = 0) => $v === null ? '—' : number_format((float) $v, $dec, ',', '.');
    $pct = fn ($v) => $v === null ? '—' : number_format((float) $v, 1, ',', '.') . ' %';
    $var = function ($v) {
        if ($v === null) return 'sin base';
        $signo = $v > 0 ? '+' : ($v < 0 ? '−' : '');
        return $signo . number_format(abs((float) $v), 1, ',', '.') . ' %';
    };
    $tono = fn ($v) => $v === null ? 'gris' : ($v > 0 ? 'sube' : ($v < 0 ? 'baja' : 'gris'));
    $fecha = fn ($f) => Carbon::parse($f)->locale('es')->isoFormat('D MMM YYYY');

    $p = $d['periodo'];
    $r = $d['resumen']['cifras'];
    $n = $d['negocios'];
    $e = $d['expansion'];
    $u = $d['usuarios']['cifras'];
    $o = $d['operacion'];
    $i = $d['ingresos']['cifras'];

    $archivoLogo = resource_path('marca/logotipo.png');
    $logo = is_file($archivoLogo) ? 'data:image/png;base64,' . base64_encode(file_get_contents($archivoLogo)) : null;
    $generado = Carbon::parse($d['generado'])->locale('es')->isoFormat('D [de] MMMM [de] YYYY, h:mm a');
@endphp
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <style>
        @page { margin: 96px 34px 52px; }
        body { font-family: 'DejaVu Sans', sans-serif; font-size: 10.5px; color: #1f2333; line-height: 1.45; }

        /* Las burbujas del fondo, detrás de todo y en todas las páginas. */
        .burbuja { position: fixed; border-radius: 50%; z-index: -1; }
        .b1 { width: 420px; height: 420px; top: -150px; right: -170px; background: #6D5DFC; opacity: 0.07; }
        .b2 { width: 260px; height: 260px; top: 300px; left: -150px; background: #FF6B5B; opacity: 0.06; }
        .b3 { width: 340px; height: 340px; bottom: -160px; right: 40px; background: #1B1464; opacity: 0.05; }
        .b4 { width: 120px; height: 120px; top: 520px; right: -40px; background: #F5A524; opacity: 0.07; }

        /* El membrete. */
        .membrete { position: fixed; top: -78px; left: 0; right: 0; height: 60px; border-bottom: 2px solid #1B1464; }
        .membrete table td { border: 0; padding: 0; background: none !important; vertical-align: middle; }
        .membrete img { height: 50px; }
        .membrete .doc { text-align: right; }
        .membrete .doc b { display: block; font-size: 13px; color: #1B1464; }
        .membrete .doc span { display: block; font-size: 8.5px; color: #6E6C88; }
        .pie { position: fixed; bottom: -36px; left: 0; right: 0; height: 18px; border-top: 1px solid #DCDAEA; padding-top: 5px; font-size: 8px; color: #8A88A3; }

        h1 { font-size: 20px; color: #1B1464; margin: 0 0 2px; }
        .alcance { color: #4A4867; font-size: 11px; }
        .generado { color: #8A88A3; font-size: 9px; font-style: italic; margin-top: 2px; }
        h2 { font-size: 13px; color: #1B1464; border-bottom: 2px solid #1B1464; padding-bottom: 3px; margin: 18px 0 8px; }
        table { width: 100%; border-collapse: collapse; }
        th { background: #1B1464; color: #fff; font-size: 9.5px; padding: 5px 6px; text-align: right; }
        th:first-child { text-align: left; }
        td { border-bottom: 1px solid #E4E2F0; padding: 4px 6px; text-align: right; }
        td:first-child { text-align: left; }
        /* Sin cebra: con fondo, las filas tapaban las burbujas a tiras (y en
           translúcido dompdf deja costuras entre celdas). La línea basta. */
        .junto { page-break-inside: avoid; }
        .kpis td { border: 0; background: none !important; padding: 0 4px 0 0; width: 25%; vertical-align: top; text-align: left; }
        .kpi { border: 1px solid #DCDAEA; border-radius: 6px; padding: 8px 10px; background: #fff; }
        .kpi small { display: block; color: #6E6C88; font-size: 9px; }
        .kpi b { display: block; font-size: 16px; color: #1B1464; margin: 2px 0; }
        .sube { color: #1F7A4D; }
        .baja { color: #B23A2B; }
        .gris { color: #8A88A3; }
        .nota { color: #6E6C88; font-size: 8.5px; font-style: italic; margin-top: 6px; }
        .salto { page-break-before: always; }
    </style>
</head>
<body>
    <div class="burbuja b1"></div>
    <div class="burbuja b2"></div>
    <div class="burbuja b3"></div>
    <div class="burbuja b4"></div>

    <div class="membrete">
        <table>
            <tr>
                <td>@if ($logo)<img src="{{ $logo }}" alt="VeciPa'Ya">@else<b>VeciPa'Ya</b>@endif</td>
                <td class="doc">
                    <b>Informe para inversionistas</b>
                    <span>Del {{ $fecha($p['desde']) }} al {{ $fecha($p['hasta']) }}</span>
                    <span>Generado el {{ $generado }}</span>
                </td>
            </tr>
        </table>
    </div>
    <div class="pie">
        Solo cifras agregadas, sin datos de personas · VeciPa'Ya
    </div>

    <h1>Así va VeciPa'Ya</h1>
    <div class="alcance">
        Del {{ $fecha($p['desde']) }} al {{ $fecha($p['hasta']) }}
        · {{ $d['alcance']['ciudad'] ?? 'Todas las ciudades' }}
        · {{ $d['alcance']['tipo'] ?? 'Todos los tipos de negocio' }}
    </div>
    <div class="generado">
        Comparado con el {{ $fecha($p['desde_anterior']) }} – {{ $fecha($p['hasta_anterior']) }}
    </div>


    <h2>Las cifras clave</h2>
    <table class="kpis">
        <tr>
            @foreach ([
                ['Negocios activos', $num($r['negocios_activos']['valor']), $r['negocios_activos']['variacion']],
                ['Usuarios registrados', $num($r['usuarios_registrados']['valor']), $r['usuarios_registrados']['variacion']],
                ['Pedidos realizados', $num($r['pedidos']['valor']), $r['pedidos']['variacion']],
                ['Ticket promedio', $pesos($r['ticket_promedio']['valor']), $r['ticket_promedio']['variacion']],
            ] as [$rot, $valor, $v])
                <td><div class="kpi"><small>{{ $rot }}</small><b>{{ $valor }}</b><span class="{{ $tono($v) }}">{{ $var($v) }} vs. antes</span></div></td>
            @endforeach
        </tr>
    </table>
    <table class="kpis" style="margin-top: 6px">
        <tr>
            @foreach ([
                ['Ventas (GMV)', $pesos($i['ventas']['valor']), $i['ventas']['variacion']],
                ['Ingreso de la plataforma', $pesos($i['ingreso']['valor']), $i['ingreso']['variacion']],
                ['Entregas a tiempo', $pct($o['cifras']['a_tiempo']['valor']), $o['cifras']['a_tiempo']['variacion']],
                ['Recompra', $pct($u['recompra']['valor']), $u['recompra']['variacion']],
            ] as [$rot, $valor, $v])
                <td><div class="kpi"><small>{{ $rot }}</small><b>{{ $valor }}</b><span class="{{ $tono($v) }}">{{ $var($v) }} vs. antes</span></div></td>
            @endforeach
        </tr>
    </table>

    <h2>Negocios</h2>
    <table>
        <tr><th>Tipo</th><th>Negocios activos</th><th>Pedidos</th><th>Ventas</th><th>% de las ventas</th></tr>
        @foreach ($n['por_tipo'] as $t)
            <tr><td>{{ $t['nombre'] }}</td><td>{{ $num($t['negocios']) }}</td><td>{{ $num($t['pedidos']) }}</td><td>{{ $pesos($t['ventas']) }}</td><td>{{ $pct($t['participacion_ventas']) }}</td></tr>
        @endforeach
    </table>
    <table style="margin-top: 8px">
        <tr><th>Indicador</th><th>Valor</th></tr>
        <tr><td>Negocios con al menos una venta</td><td>{{ $num($n['actividad']['con_ventas']['valor']) }} de {{ $num($n['actividad']['activos']['valor']) }} ({{ $pct($n['actividad']['porcentaje_con_ventas']) }})</td></tr>
        <tr><td>Negocios nuevos en el periodo</td><td>{{ $num($n['actividad']['nuevos']) }}</td></tr>
        <tr><td>Días del alta a la primera venta (promedio)</td><td>{{ $num($n['arranque']['promedio_dias'], 1) }} ({{ $num($n['arranque']['medidos']) }} medidos)</td></tr>
    </table>

    <h2>Expansión por ciudad</h2>
    <table>
        <tr><th>Ciudad</th><th>Negocios</th><th>Conjuntos</th><th>Pedidos</th><th>Ventas</th><th>Compradores</th></tr>
        @forelse ($e['por_ciudad'] as $c)
            <tr><td>{{ $c['ciudad'] }}@if ($c['departamento']) <span class="gris">· {{ $c['departamento'] }}</span>@endif</td><td>{{ $num($c['negocios']) }}</td><td>{{ $num($c['conjuntos']) }}</td><td>{{ $num($c['pedidos']) }}</td><td>{{ $pesos($c['ventas']) }}</td><td>{{ $num($c['compradores']) }}</td></tr>
        @empty
            <tr><td colspan="6">Sin presencia todavía</td></tr>
        @endforelse
    </table>

    <div class="salto"></div>
    <h2>Usuarios</h2>
    <table>
        <tr><th>Indicador</th><th>Este periodo</th><th>Periodo anterior</th><th>Variación</th></tr>
        @foreach ([
            ['Usuarios registrados', $u['registrados'], $num],
            ['Usuarios nuevos', $u['nuevos'], $num],
            ['Compradores activos', $u['activos'], $num],
            ['Recompra (2 o más pedidos)', $u['recompra'], $pct],
            ['Pedidos por comprador activo', $u['pedidos_por_comprador'], fn ($v) => $num($v, 1)],
        ] as [$rot, $cifra, $fmt])
            <tr><td>{{ $rot }}</td><td>{{ $fmt($cifra['valor']) }}</td><td>{{ $fmt($cifra['anterior']) }}</td><td class="{{ $tono($cifra['variacion']) }}">{{ $var($cifra['variacion']) }}</td></tr>
        @endforeach
        <tr><td>Viven en un conjunto</td><td>{{ $num($u['en_conjuntos']['valor']) }}</td><td colspan="2">{{ $pct($u['en_conjuntos']['porcentaje']) }} de los registrados</td></tr>
    </table>

    <h2>Operación</h2>
    <table>
        <tr><th>Indicador</th><th>Este periodo</th><th>Periodo anterior</th><th>Variación</th></tr>
        @foreach ([
            ['Minutos en camino (promedio)', $o['cifras']['minutos_en_camino'], fn ($v) => $num($v, 1)],
            ['Minutos de punta a punta (promedio)', $o['cifras']['minutos_totales'], fn ($v) => $num($v, 1)],
            ['Entregas a tiempo', $o['cifras']['a_tiempo'], $pct],
            ['Pedidos cancelados', $o['cifras']['porcentaje_cancelados'], $pct],
            ['Domiciliarios activos', $o['cifras']['domiciliarios_activos'], $num],
            ['Pedidos por domiciliario', $o['cifras']['pedidos_por_domiciliario'], fn ($v) => $num($v, 1)],
        ] as [$rot, $cifra, $fmt])
            <tr><td>{{ $rot }}</td><td>{{ $fmt($cifra['valor']) }}</td><td>{{ $fmt($cifra['anterior']) }}</td><td class="{{ $tono($cifra['variacion']) }}">{{ $var($cifra['variacion']) }}</td></tr>
        @endforeach
        <tr><td>Domiciliarios disponibles ahora</td><td>{{ $num($o['flota']['disponibles']) }}</td><td colspan="2">de {{ $num($o['flota']['habilitados']) }} habilitados</td></tr>
    </table>

    <h2>Ingresos</h2>
    <table>
        <tr><th>Indicador</th><th>Este periodo</th><th>Periodo anterior</th><th>Variación</th></tr>
        @foreach ([
            ['Ventas (GMV)', $i['ventas'], $pesos],
            ['Comisiones de los negocios', $i['comisiones'], $pesos],
            ['Margen de los domicilios', $i['margen_domicilio'], $pesos],
            ['Ingreso de la plataforma', $i['ingreso'], $pesos],
            ['Comisión efectiva (take rate)', $i['take_rate'], $pct],
            ['Subsidios al domicilio', $i['subsidios'], $pesos],
        ] as [$rot, $cifra, $fmt])
            <tr><td>{{ $rot }}</td><td>{{ $fmt($cifra['valor']) }}</td><td>{{ $fmt($cifra['anterior']) }}</td><td class="{{ $tono($cifra['variacion']) }}">{{ $var($cifra['variacion']) }}</td></tr>
        @endforeach
    </table>
    <div class="nota">
        Ingreso = comisiones + margen de los domicilios (lo que pagó el comprador por el domicilio menos lo que se lleva el
        domiciliario). Los subsidios ya están descontados de ese margen: se informan aparte, no se restan dos veces.
    </div>

    <div class="junto">
    <h2>Mes a mes</h2>
    @php
        $ing = collect($d['ingresos']['meses'])->keyBy('mes');
        $act = collect($d['usuarios']['meses'])->keyBy('mes');
        // Los meses de antes del primer movimiento no dicen nada: en papel
        // solo ocupan media hoja de ceros.
        $meses = collect($d['resumen']['meses'])
            ->skipUntil(fn ($m) => $m['pedidos'] > 0 || $m['negocios_nuevos'] > 0 || $m['usuarios_nuevos'] > 0)
            ->values();
        if ($meses->isEmpty()) {
            $meses = collect($d['resumen']['meses'])->take(-1);
        }
    @endphp
    <table>
        <tr><th>Mes</th><th>Pedidos</th><th>Ventas</th><th>Ingreso</th><th>Compradores activos</th><th>Negocios nuevos</th><th>Usuarios nuevos</th></tr>
        @foreach ($meses as $m)
            <tr>
                <td>{{ $m['etiqueta'] }} {{ $m['anio'] }}@if ($m['en_curso']) <span class="gris">(en curso)</span>@endif</td>
                <td>{{ $num($m['pedidos']) }}</td>
                <td>{{ $pesos($m['ventas']) }}</td>
                <td>{{ $pesos($ing[$m['mes']]['ingreso'] ?? 0) }}</td>
                <td>{{ $num($act[$m['mes']]['activos'] ?? 0) }}</td>
                <td>{{ $num($m['negocios_nuevos']) }}</td>
                <td>{{ $num($m['usuarios_nuevos']) }}</td>
            </tr>
        @endforeach
    </table>
    </div>
    <div class="nota">
        Pedido = entregado y con el pago confirmado. Negocio activo = visible en la app. Usuario registrado = cuenta de
        comprador. El periodo anterior es uno de igual número de días, justo antes.
    </div>
</body>
</html>
