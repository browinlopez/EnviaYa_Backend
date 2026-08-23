{{--
    EL CORREO DE VERIFICACIÓN.

    Es el primer contacto de la plataforma con una persona, y sin él no puede
    entrar: el login rechaza a quien no verificó.

    SIN EMOJIS, y no por gusto. Tres razones concretas:

    · Los lectores de pantalla los leen en voz alta, uno por uno («cara
      sonriente con corazones»), y convierten una frase de diez palabras en
      media hora de ruido.
    · Algunos filtros antispam los puntúan en contra, y este correo es
      justamente el que NO puede caer en no deseados.
    · En clientes viejos y en la vista de texto plano salen como cuadros o
      signos de interrogación.

    El logotipo lo pone la cabecera; acá va solo el mensaje.
--}}
@component('mail::message')
# Bienvenido a VeciPa’Ya

Gracias por registrarte. VeciPa’Ya conecta a los vecinos de un conjunto con los
negocios de su barrio.

## Confirma tu correo

Solo falta un paso. Pulsa el botón para activar tu cuenta:

@component('mail::button', ['url' => $actionUrl])
Verificar mi cuenta
@endcomponent

Si el botón no funciona, copia esta dirección en tu navegador:

<small style="word-break: break-all; color: #6b7280;">{{ $actionUrl }}</small>

El enlace caduca en una hora. Si se te pasa, puedes pedir otro desde la
aplicación.

---

Si no creaste esta cuenta, ignora este correo: sin confirmar, no se activa.

Equipo VeciPa’Ya
@endcomponent
