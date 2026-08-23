<!DOCTYPE html>
<html lang="es">
<head>
 <meta charset="UTF-8">
 <title>Correo verificado | VeciPa’Ya</title>
 <meta name="viewport" content="width=device-width, initial-scale=1">

 <style>
 body {
 font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
 background-color: #f3f4f6;
 margin: 0;
 padding: 0;
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

 h1 {
 color: #2563eb;
 font-size: 26px;
 margin-bottom: 10px;
 }

 p {
 color: #4b5563;
 font-size: 16px;
 line-height: 1.5;
 margin-bottom: 25px;
 }

 /* El visto, dibujado con dos bordes y un giro. Sin emoji: el lector de
    pantalla decia "marca de verificacion blanca" antes del titulo. */
 .success-icon {
 width: 64px;
 height: 64px;
 margin: 0 auto 16px;
 border-radius: 50%;
 background: #dcfce7;
 position: relative;
 }
 .success-icon::after {
 content: '';
 position: absolute;
 left: 23px;
 top: 15px;
 width: 14px;
 height: 26px;
 border: solid #16a34a;
 border-width: 0 4px 4px 0;
 border-radius: 2px;
 transform: rotate(45deg);
 }

 .button {
 display: inline-block;
 background-color: #2563eb;
 color: #ffffff;
 text-decoration: none;
 padding: 12px 22px;
 border-radius: 8px;
 font-weight: 600;
 transition: background 0.2s ease;
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

 <div class="success-icon" aria-hidden="true"></div>

 <h1>¡Correo verificado!</h1>

 <p>Tu correo electrónico fue verificado correctamente.<br>
 Ya puedes disfrutar de todas las funcionalidades de <strong>VeciPa’Ya</strong>.</p>

 <a href="https://api.enviaya.com.co/" class="button">
 Ir a VeciPa’Ya
 </a>

 <div class="footer">
 © {{ date('Y') }} VeciPa’Ya. Todos los derechos reservados.
 </div>
 </div>

</body>
</html>
