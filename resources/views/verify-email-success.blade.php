<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Correo verificado</title>
    <style>
        body {
            font-family: system-ui, -apple-system, BlinkMacSystemFont;
            background: #f9fafb;
            display: flex;
            justify-content: center;
            align-items: center;
            height: 100vh;
        }
        .card {
            background: #fff;
            padding: 40px;
            border-radius: 16px;
            box-shadow: 0 10px 25px rgba(0,0,0,.1);
            text-align: center;
            max-width: 400px;
        }
        h1 {
            color: #16a34a;
            margin-bottom: 10px;
        }
        p {
            color: #374151;
        }
        a {
            display: inline-block;
            margin-top: 20px;
            background: #2563eb;
            color: #fff;
            padding: 12px 20px;
            border-radius: 10px;
            text-decoration: none;
        }
    </style>
</head>
<body>
    <div class="card">
        <h1>✅ Correo verificado</h1>
        <p>{{ $message }}</p>
        <a href="vecipaya://login">Abrir la app</a>
    </div>
</body>
</html>
