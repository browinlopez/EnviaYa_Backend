{{--
    RESUMEN DIARIO DE UN ÁREA

    HTML a la antigua —tablas, estilos en línea— porque los clientes de correo no
    entienden flexbox ni grid, y Gmail borra el <style> del encabezado. Lo que se
    ve bien en el navegador no es lo que se ve bien en Outlook.

    Sin imágenes: la mayoría de los clientes las bloquean por defecto, así que un
    aviso que dependa de una imagen llega vacío. El color va en el texto.
--}}
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $area->name }} · pendientes</title>
</head>
<body style="margin:0;padding:0;background:#f4f5f9;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;color:#1f2333;">

<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f4f5f9;padding:24px 12px;">
<tr><td align="center">

    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:560px;background:#ffffff;border:1px solid #e3e6f0;border-radius:12px;overflow:hidden;">

        {{-- Encabezado --}}
        <tr>
            <td style="padding:24px 24px 8px;">
                <div style="font-size:12px;letter-spacing:.06em;text-transform:uppercase;color:#8a90a6;font-weight:700;">
                    {{ $area->name }}
                </div>
                <div style="font-size:20px;font-weight:700;margin-top:4px;">
                    Lo que hay pendiente hoy
                </div>
                <div style="font-size:13px;color:#6b7189;margin-top:6px;line-height:1.5;">
                    {{-- Se dice POR QUÉ llega esto: un correo automático sin
                         explicación se marca como correo basura. --}}
                    Solo aparece lo que está mal y no se arregla solo, y solo lo
                    que le toca a tu área. Si un día no hay nada, este correo no
                    se envía.
                </div>
            </td>
        </tr>

        {{-- Asuntos --}}
        <tr>
            <td style="padding:16px 24px 8px;">
                @foreach ($asuntos as $a)
                    <table role="presentation" width="100%" cellpadding="0" cellspacing="0"
                           style="margin-bottom:12px;border:1px solid #e3e6f0;border-radius:10px;">
                        <tr>
                            <td style="padding:14px 16px;">
                                <div style="font-size:15px;font-weight:700;color:{{ $a['urgente'] ? '#c02626' : '#1f2333' }};">
                                    {{-- El número va en el título: es lo que
                                         decide si esto se atiende hoy o mañana. --}}
                                    {{ $a['titulo'] }}
                                    <span style="display:inline-block;margin-left:6px;padding:1px 8px;border-radius:999px;background:{{ $a['urgente'] ? '#fdeaea' : '#eef0f7' }};color:{{ $a['urgente'] ? '#c02626' : '#6b7189' }};font-size:12px;font-weight:700;">
                                        {{ $a['cuantos'] }}
                                    </span>
                                </div>
                                <div style="font-size:13px;color:#4b5167;margin-top:6px;line-height:1.5;">
                                    {{ $a['detalle'] }}
                                </div>
                                @if ($a['ajeno'])
                                    {{-- A las áreas que supervisan les llega lo
                                         urgente de las demás. Sin decir de quién
                                         es, parecería su tarea. --}}
                                    <div style="font-size:12px;color:#8a90a6;margin-top:4px;">
                                        Lo atiende {{ $a['de'] }}. Llega acá porque supervisas.
                                    </div>
                                @endif
                                <div style="margin-top:10px;">
                                    <a href="{{ rtrim($urlPanel, '/') . $a['ruta'] }}"
                                       style="font-size:13px;color:#1b1464;text-decoration:none;font-weight:600;">
                                        Abrir en el panel &rarr;
                                    </a>
                                </div>
                            </td>
                        </tr>
                    </table>
                @endforeach
            </td>
        </tr>

        {{-- Pie --}}
        <tr>
            <td style="padding:8px 24px 24px;border-top:1px solid #e3e6f0;">
                <div style="font-size:12px;color:#8a90a6;line-height:1.6;padding-top:14px;">
                    Este resumen se arma con los permisos de tu área: solo trae
                    asuntos de secciones que puedes abrir.
                    <br>
                    Para dejar de recibirlo, pídeselo a Tecnología.
                </div>
            </td>
        </tr>
    </table>

</td></tr>
</table>

</body>
</html>
