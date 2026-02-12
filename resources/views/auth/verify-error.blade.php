<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Error de verificación | VeciPa’Ya</title>
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

        .error-icon {
            font-size: 64px;
            color: #ef4444;
            margin-bottom: 15px;
        }

        h1 {
            color: #dc2626;
            font-size: 26px;
            margin-bottom: 10px;
        }

        p {
            color: #4b5563;
            font-size: 16px;
            line-height: 1.5;
            margin-bottom: 25px;
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

        .secondary {
            display: block;
            margin-top: 15px;
            font-size: 14px;
            color: #6b7280;
            text-decoration: none;
        }

        .secondary:hover {
            text-decoration: underline;
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
            <img src="https://vecipaya.com/logo.png" alt="VeciPa’Ya">
        </div>

        <div class="error-icon">⚠️</div>

        <h1>Verificación fallida</h1>

        <p>
            {{ $message ?? 'El enlace de verificación no es válido o ha expirado.' }}
        </p>

        <a href="https://api.vecipaya.com" class="button">
            Volver a VeciPa’Ya
        </a>

        <a href="mailto:soporte@vecipaya.com" class="secondary">
            Contactar soporte
        </a>

        <div class="footer">
            © {{ date('Y') }} VeciPa’Ya. Todos los derechos reservados.
        </div>
    </div>

</body>
</html>
