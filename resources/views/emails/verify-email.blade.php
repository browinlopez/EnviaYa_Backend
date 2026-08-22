@php
    $primaryColor = '#2563eb'; // Azul VeciPa’Ya
@endphp

@component('mail::message')
<div style="text-align:center;margin-bottom:20px;">
    <img src="{{ rtrim(config('services.sitio.url'), '/') }}/logotipo.png"  width="120" alt="VeciPa’Ya">
</div>

# ¡Bienvenido a VeciPa’Ya! 🎉

Gracias por registrarte en **VeciPa’Ya**, la plataforma que conecta a los vecinos con los negocios de su conjunto residencial.

---

### 🔐 Verifica tu cuenta
Para activar tu cuenta y empezar a usar VeciPa’Ya, confirma tu correo electrónico haciendo clic en el siguiente botón:

@component('mail::button', ['url' => $actionUrl, 'color' => 'primary'])
Verificar mi cuenta
@endcomponent

---

### ❓ ¿Por qué es importante?
✔ Protegemos tu cuenta  
✔ Evitamos registros falsos  
✔ Garantizamos una mejor experiencia  

Si **no creaste esta cuenta**, puedes ignorar este correo.

---

Gracias por confiar en nosotros,  
**Equipo VeciPa’Ya** 💙  

<small style="color:#6b7280;">
Este enlace expirará en 60 minutos.
</small>
@endcomponent
