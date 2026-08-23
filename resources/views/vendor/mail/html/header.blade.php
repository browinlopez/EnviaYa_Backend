@props(['url'])
{{--
    LA CABECERA DE TODOS LOS CORREOS.

    La de Laravel trae una regla que muerde: si `config('app.name')` vale
    exactamente «Laravel», pinta EL LOGO DE LARAVEL. Y `APP_NAME` estaba en
    `Laravel`, asi que el correo de verificacion que recibia cada vecino
    llegaba con el logotipo del framework arriba y «© 2026 Laravel» al pie.

    Aca no se depende de esa variable: el logotipo es siempre el de la marca, y
    sale del mismo sitio que el resto —`services.sitio.url`— para que el dia
    que cambie el dominio no haya que buscarlo en tres plantillas.
--}}
<tr>
<td class="header">
<a href="{{ $url }}" style="display: inline-block;">
<img
    src="{{ rtrim(config('services.sitio.url'), '/') }}/logotipo.png"
    class="logo"
    alt="VeciPa’Ya"
    style="max-height: 56px; width: auto;"
>
</a>
</td>
</tr>
