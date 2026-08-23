<x-mail::layout>
{{-- Header --}}
<x-slot:header>
<x-mail::header :url="config('app.url')">
{{ config('app.name') }}
</x-mail::header>
</x-slot:header>

{{-- Body --}}
{!! $slot !!}

{{-- Subcopy --}}
@isset($subcopy)
<x-slot:subcopy>
<x-mail::subcopy>
{!! $subcopy !!}
</x-mail::subcopy>
</x-slot:subcopy>
@endisset

{{-- Footer --}}
<x-slot:footer>
<x-mail::footer>
{{-- Ni `app.name` ni ingles: la variable decia «Laravel» y el pie del correo
     de cada vecino terminaba en «© 2026 Laravel. All rights reserved.». --}}
© {{ date('Y') }} VeciPa'Ya. Todos los derechos reservados.
</x-mail::footer>
</x-slot:footer>
</x-mail::layout>
