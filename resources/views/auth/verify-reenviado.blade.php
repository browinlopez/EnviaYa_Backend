<!DOCTYPE html>
<html lang="es">
<head>
 <meta charset="UTF-8">
 <title>Revisa tu correo | VeciPa’Ya</title>
 <meta name="viewport" content="width=device-width, initial-scale=1">

 <style>
 body {
 font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
 background-color: #f3f4f6;
 margin: 0;
 padding: 0 16px;
 }

 .container {
 max-width: 480px;
 margin: 80px auto;
 background: #ffffff;
 border-radius: 12px;
 padding: 40px 30px;
 text-align: center;
 box-shadow: 0 10px 25px rgba(0,0,0,0.08);
 }

 .logo {
 margin-bottom: 20px;
 }

 .logo img {
 width: 120px;
 }

 /* Un sobre dibujado con CSS, por la misma razón que el visto y la equis
    de las otras dos páginas: sin emoji. */
 .mail-icon {
 width: 64px;
 height: 64px;
 margin: 0 auto 16px;
 border-radius: 50%;
 background: #dbeafe;
 position: relative;
 }
 .mail-icon::before {
 content: '';
 position: absolute;
 left: 18px;
 top: 21px;
 width: 28px;
 height: 20px;
 border: 3px solid #2563eb;
 border-radius: 3px;
 box-sizing: border-box;
 }
 .mail-icon::after {
 content: '';
 position: absolute;
 left: 24px;
 top: 17px;
 width: 13px;
 height: 13px;
 border: solid #2563eb;
 border-width: 0 3px 3px 0;
 transform: rotate(45deg);
 }

 h1 {
 color: #1f2933;
 font-size: 26px;
 margin-bottom: 10px;
 }

 p {
 color: #4b5563;
 font-size: 16px;
 line-height: 1.5;
 margin-bottom: 16px;
 }

 .button {
 display: inline-block;
 margin-top: 10px;
 background-color: #2563eb;
 color: #ffffff;
 text-decoration: none;
 padding: 12px 22px;
 border-radius: 8px;
 font-weight: 600;
 }

 .button:hover {
 background-color: #1e40af;
 }

 .footer {
 margin-top: 30px;
 font-size: 13px;
 color: #9ca3af;
 }
 </style>
</head>
<body>

 <div class="container">
 <div class="logo">
 <img src="{{ rtrim(config('services.sitio.url'), '/') }}/logotipo.png" alt="VeciPa’Ya">
 </div>

 <div class="mail-icon" aria-hidden="true"></div>

 <h1>Revisa tu correo</h1>

 {{-- Mismo texto exista o no la cuenta: no se confirma quién está registrado. --}}
 <p>Si <strong>{{ $email }}</strong> tiene una cuenta pendiente de verificar, te enviamos un enlace nuevo.</p>

 <p>Abre el correo más reciente de VeciPa’Ya y pulsa <strong>Verificar mi cuenta</strong>. El enlace dura una hora. Si no lo ves, revisa la carpeta de spam.</p>

 <a href="{{ config('services.sitio.url') }}" class="button">
 Volver a VeciPa’Ya
 </a>

 <div class="footer">
 © {{ date('Y') }} VeciPa’Ya. Todos los derechos reservados.
 </div>
 </div>

</body>
</html>
