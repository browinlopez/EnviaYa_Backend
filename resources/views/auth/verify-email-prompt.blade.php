<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Verifica tu correo</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light d-flex justify-content-center align-items-center vh-100">
    <div class="card p-4 shadow" style="width: 400px;">
        <h3 class="mb-3 text-center">Correo no verificado</h3>
        <p>Tu correo electrónico aún no ha sido verificado. Para poder iniciar sesión correctamente, debes verificarlo.</p>

        <form action="{{ route('verification.send') }}" method="POST">
            @csrf
            <button type="submit" class="btn btn-primary w-100 mb-2">Enviar correo de verificación</button>
        </form>

        <a href="{{ route('login') }}" class="btn btn-secondary w-100">Regresar al login</a>
    </div>
</body>
</html>