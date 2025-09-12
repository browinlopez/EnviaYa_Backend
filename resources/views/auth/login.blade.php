
  <!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login EnviaYA</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css"/>
    <style>
            * {
                margin: 0;
                padding: 0;
                box-sizing: border-box;
            }

            body {
                font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
                min-height: 100vh;
                background: linear-gradient(135deg, #1B1464 0%, #2d1b8e 40%, #4f46e5 100%);
                display: flex;
                align-items: center;
                justify-content: center;
                position: relative;
                overflow: hidden;
            }

            /* Animated background particles */
            .particles {
                position: absolute;
                top: 0;
                left: 0;
                width: 100%;
                height: 100%;
                overflow: hidden;
                z-index: 1;
            }

            .particle {
                position: absolute;
                width: 4px;
                height: 4px;
                background: rgba(255, 255, 255, 0.1);
                border-radius: 50%;
                animation: float-particles 15s infinite linear;
            }

            .particle:nth-child(1) {
                left: 10%;
                animation-delay: 0s;
            }

            .particle:nth-child(2) {
                left: 20%;
                animation-delay: 2s;
            }

            .particle:nth-child(3) {
                left: 30%;
                animation-delay: 4s;
            }

            .particle:nth-child(4) {
                left: 40%;
                animation-delay: 6s;
            }

            .particle:nth-child(5) {
                left: 50%;
                animation-delay: 8s;
            }

            .particle:nth-child(6) {
                left: 60%;
                animation-delay: 10s;
            }

            .particle:nth-child(7) {
                left: 70%;
                animation-delay: 12s;
            }

            .particle:nth-child(8) {
                left: 80%;
                animation-delay: 14s;
            }

            .particle:nth-child(9) {
                left: 90%;
                animation-delay: 16s;
            }

            @keyframes float-particles {
                0% {
                    transform: translateY(100vh) rotate(0deg);
                    opacity: 0;
                }

                10% {
                    opacity: 1;
                }

                90% {
                    opacity: 1;
                }

                100% {
                    transform: translateY(-100vh) rotate(360deg);
                    opacity: 0;
                }
            }

            /* Floating shapes */
            .floating-shape {
                position: absolute;
                background: linear-gradient(135deg, rgba(255, 255, 255, 0.1), rgba(255, 255, 255, 0.05));
                border-radius: 50%;
                animation: float-shapes 20s ease-in-out infinite;
            }

            .shape-1 {
                width: 80px;
                height: 80px;
                top: 10%;
                left: 10%;
                animation-delay: 0s;
            }

            .shape-2 {
                width: 60px;
                height: 60px;
                top: 20%;
                right: 15%;
                animation-delay: 5s;
            }

            .shape-3 {
                width: 100px;
                height: 100px;
                bottom: 15%;
                left: 15%;
                animation-delay: 10s;
            }

            .shape-4 {
                width: 40px;
                height: 40px;
                bottom: 30%;
                right: 20%;
                animation-delay: 15s;
            }

            @keyframes float-shapes {

                0%,
                100% {
                    transform: translateY(0px) rotate(0deg);
                }

                33% {
                    transform: translateY(-30px) rotate(120deg);
                }

                66% {
                    transform: translateY(15px) rotate(240deg);
                }
            }

            /* Main container */
            .login-container {
                position: relative;
                z-index: 10;
                width: 100%;
                max-width: 450px;
                margin: 0 20px;
                animation: slideInUp 0.8s ease-out;
            }

            @keyframes slideInUp {
                from {
                    opacity: 0;
                    transform: translateY(50px);
                }

                to {
                    opacity: 1;
                    transform: translateY(0);
                }
            }

            /* Glass morphism card */
            .login-card {
                background: rgba(255, 255, 255, 0.1);
                backdrop-filter: blur(20px);
                border-radius: 20px;
                padding: 40px;
                border: 1px solid rgba(255, 255, 255, 0.2);
                box-shadow: 0 25px 50px rgba(0, 0, 0, 0.3);
                position: relative;
                overflow: hidden;
            }

            .login-card::before {
                content: '';
                position: absolute;
                top: 0;
                left: 0;
                right: 0;
                height: 1px;
                background: linear-gradient(90deg, transparent, rgba(255, 255, 255, 0.4), transparent);
                animation: shimmer 3s ease-in-out infinite;
            }

            @keyframes shimmer {
                0% {
                    transform: translateX(-100%);
                }

                100% {
                    transform: translateX(100%);
                }
            }

            /* Logo and header */
            .login-header {
                text-align: center;
                margin-bottom: 40px;
            }

            .logo {
                width: 80px;
                height: 80px;
                background: linear-gradient(135deg, #f59e0b, #ef4444);
                border-radius: 20px;
                display: flex;
                align-items: center;
                justify-content: center;
                font-size: 2.5rem;
                margin: 0 auto 20px;
                animation: pulse-logo 2s ease-in-out infinite alternate;
                box-shadow: 0 10px 30px rgba(245, 158, 11, 0.3);
            }

            @keyframes pulse-logo {
                0% {
                    transform: scale(1);
                }

                100% {
                    transform: scale(1.05);
                }
            }

            .login-title {
                color: white;
                font-size: 2rem;
                font-weight: 800;
                margin-bottom: 10px;
                background: linear-gradient(135deg, #ffffff, #a78bfa);
                background-clip: text;
                -webkit-background-clip: text;
                color: transparent;
            }

            .login-subtitle {
                color: rgba(255, 255, 255, 0.8);
                font-size: 1rem;
                font-weight: 300;
            }

            /* Form styles */
            .login-form {
                display: flex;
                flex-direction: column;
                gap: 25px;
            }

            .form-group {
                position: relative;
            }

            .form-input {
                width: 100%;
                padding: 18px 20px 18px 60px;
                border: 2px solid rgba(255, 255, 255, 0.2);
                border-radius: 15px;
                background: rgba(255, 255, 255, 0.1);
                color: white;
                font-size: 1rem;
                transition: all 0.3s ease;
                backdrop-filter: blur(10px);
            }

            .form-input::placeholder {
                color: rgba(255, 255, 255, 0.6);
            }

            .form-input:focus {
                outline: none;
                border-color: #f59e0b;
                background: rgba(255, 255, 255, 0.15);
                box-shadow: 0 0 20px rgba(245, 158, 11, 0.3);
                transform: translateY(-2px);
            }

            .input-icon {
                position: absolute;
                left: 20px;
                top: 50%;
                transform: translateY(-50%);
                font-size: 1.2rem;
                color: rgba(255, 255, 255, 0.6);
                transition: all 0.3s ease;
            }

            .form-group:focus-within .input-icon {
                color: #f59e0b;
                transform: translateY(-50%) scale(1.1);
            }

            /* Password toggle */
            .password-toggle {
                position: absolute;
                right: 20px;
                top: 50%;
                transform: translateY(-50%);
                background: none;
                border: none;
                color: rgba(255, 255, 255, 0.6);
                cursor: pointer;
                font-size: 1.2rem;
                transition: all 0.3s ease;
            }

            .password-toggle:hover {
                color: #f59e0b;
                transform: translateY(-50%) scale(1.1);
            }

            /* Remember me and forgot password */
            .form-options {
                display: flex;
                justify-content: space-between;
                align-items: center;
                margin: 10px 0;
            }

            .checkbox-container {
                display: flex;
                align-items: center;
                gap: 10px;
                color: rgba(255, 255, 255, 0.8);
                font-size: 0.9rem;
            }

            .custom-checkbox {
                width: 20px;
                height: 20px;
                border: 2px solid rgba(255, 255, 255, 0.3);
                border-radius: 6px;
                display: flex;
                align-items: center;
                justify-content: center;
                cursor: pointer;
                transition: all 0.3s ease;
            }

            .custom-checkbox:hover {
                border-color: #f59e0b;
                background: rgba(245, 158, 11, 0.1);
            }

            .custom-checkbox.checked {
                background: linear-gradient(135deg, #f59e0b, #ef4444);
                border-color: #f59e0b;
            }

            .custom-checkbox.checked::after {
                content: '✓';
                color: white;
                font-size: 0.8rem;
                font-weight: bold;
            }

            .forgot-password {
                color: rgba(255, 255, 255, 0.8);
                text-decoration: none;
                font-size: 0.9rem;
                transition: all 0.3s ease;
            }

            .forgot-password:hover {
                color: #f59e0b;
                text-decoration: underline;
            }

            /* Submit button */
            .btn-login {
                background: linear-gradient(135deg, #f59e0b, #ef4444);
                color: white;
                padding: 18px 30px;
                border: none;
                border-radius: 15px;
                font-size: 1.1rem;
                font-weight: 600;
                cursor: pointer;
                transition: all 0.3s ease;
                position: relative;
                overflow: hidden;
                box-shadow: 0 10px 30px rgba(245, 158, 11, 0.3);
                margin-top: 10px;
            }

            .btn-login::before {
                content: '';
                position: absolute;
                top: 0;
                left: -100%;
                width: 100%;
                height: 100%;
                background: linear-gradient(135deg, rgba(255, 255, 255, 0.2), transparent);
                transition: left 0.5s;
            }

            .btn-login:hover::before {
                left: 100%;
            }

            .btn-login:hover {
                transform: translateY(-3px);
                box-shadow: 0 15px 40px rgba(245, 158, 11, 0.4);
            }

            .btn-login:active {
                transform: translateY(-1px);
            }

            /* Loading state */
            .btn-login.loading {
                opacity: 0.8;
                cursor: not-allowed;
            }

            .btn-login.loading::after {
                content: '';
                position: absolute;
                top: 50%;
                left: 50%;
                width: 20px;
                height: 20px;
                margin: -10px 0 0 -10px;
                border: 2px solid transparent;
                border-top: 2px solid white;
                border-radius: 50%;
                animation: spin 1s linear infinite;
            }

            @keyframes spin {
                0% {
                    transform: rotate(0deg);
                }

                100% {
                    transform: rotate(360deg);
                }
            }

            /* Social login */
            .social-login {
                margin-top: 30px;
                text-align: center;
            }

            .social-title {
                color: rgba(255, 255, 255, 0.6);
                font-size: 0.9rem;
                margin-bottom: 20px;
                position: relative;
            }

            .social-title::before,
            .social-title::after {
                content: '';
                position: absolute;
                top: 50%;
                width: 40%;
                height: 1px;
                background: rgba(255, 255, 255, 0.2);
            }

            .social-title::before {
                left: 0;
            }

            .social-title::after {
                right: 0;
            }

            .social-buttons {
                display: flex;
                gap: 15px;
                justify-content: center;
            }

            .social-btn {
                width: 50px;
                height: 50px;
                border-radius: 50%;
                border: 2px solid rgba(255, 255, 255, 0.2);
                background: rgba(255, 255, 255, 0.1);
                display: flex;
                align-items: center;
                justify-content: center;
                color: white;
                text-decoration: none;
                font-size: 1.2rem;
                transition: all 0.3s ease;
                backdrop-filter: blur(10px);
            }

            .social-btn:hover {
                border-color: #f59e0b;
                background: rgba(245, 158, 11, 0.2);
                transform: translateY(-2px) scale(1.05);
            }

            /* Register link */
            .register-link {
                text-align: center;
                margin-top: 30px;
                color: rgba(255, 255, 255, 0.8);
            }

            .register-link a {
                color: #f59e0b;
                text-decoration: none;
                font-weight: 600;
                transition: all 0.3s ease;
            }

            .register-link a:hover {
                color: #ef4444;
                text-decoration: underline;
            }

            /* Back button */
            .back-button {
                position: absolute;
                top: 30px;
                left: 30px;
                background: rgba(255, 255, 255, 0.1);
                color: white;
                border: none;
                width: 50px;
                height: 50px;
                border-radius: 50%;
                display: flex;
                align-items: center;
                justify-content: center;
                cursor: pointer;
                transition: all 0.3s ease;
                backdrop-filter: blur(10px);
                border: 1px solid rgba(255, 255, 255, 0.2);
                z-index: 20;
            }

            .back-button:hover {
                background: rgba(255, 255, 255, 0.2);
                transform: translateX(-3px);
            }

            /* Responsive */
            @media (max-width: 480px) {
                .login-card {
                    padding: 30px 20px;
                    margin: 20px;
                }

                .login-title {
                    font-size: 1.8rem;
                }

                .back-button {
                    top: 20px;
                    left: 20px;
                    width: 45px;
                    height: 45px;
                }
            }

            /* Success animation */
            @keyframes success {
                0% {
                    transform: scale(1);
                }

                50% {
                    transform: scale(1.05);
                }

                100% {
                    transform: scale(1);
                }
            }

            .success-animation {
                animation: success 0.6s ease-in-out;
            }
</style>
</head>
<body>
 <!-- Partículas y formas -->
    <div class="particles">
        <div class="particle"></div><div class="particle"></div><div class="particle"></div>
        <div class="particle"></div><div class="particle"></div><div class="particle"></div>
        <div class="particle"></div><div class="particle"></div><div class="particle"></div>
    </div>
    <div class="floating-shape shape-1"></div>
    <div class="floating-shape shape-2"></div>
    <div class="floating-shape shape-3"></div>
    <div class="floating-shape shape-4"></div>

    <!-- Login -->
    <div class="login-container">
        <div class="login-card">
            <div class="login-header">
                <div class="logo">🚚</div>
                <h1 class="login-title">Bienvenido a EnviaYA</h1>
                <p class="login-subtitle">Ingresa a tu cuenta para continuar</p>
            </div>

            <!-- Formulario Laravel -->
            <form method="POST" action="{{ route('login') }}" class="login-form" id="loginForm">
                @csrf
                <div class="form-group">
                    <span class="input-icon">📧</span>
                    <input id="email" type="email" name="email" placeholder="Correo electrónico" class="form-input" value="{{ old('email') }}" required autofocus>
                    <x-input-error :messages="$errors->get('email')" class="mt-2" />
                </div>

                <div class="form-group">
                    <span class="input-icon">🔒</span>
                    <input id="password" type="password" name="password" placeholder="Contraseña" class="form-input" required autocomplete="current-password">
                    <button type="button" class="password-toggle" id="passwordToggle">👁️</button>
                    <x-input-error :messages="$errors->get('password')" class="mt-2" />
                </div>

                {{-- <div class="checkbox-container">
                    <div class="custom-checkbox" id="rememberMe"></div>
                    <label for="remember_me">Recordarme</label>
                    <input type="hidden" name="remember" id="remember_me" value="0">
                </div> --}}

                @if(Route::has('password.request'))
                    <a class="forgot-password" href="{{ route('password.request') }}">¿Olvidaste tu contraseña?</a>
                @endif

                <button type="submit" class="btn-login" id="loginBtn">Iniciar Sesión</button>
            </form>
        </div>
    </div>

    <script>
        // Toggle password
        const passwordToggle = document.getElementById('passwordToggle');
        const passwordInput = document.getElementById('password');
        passwordToggle.addEventListener('click', function() {
            const type = passwordInput.getAttribute('type') === 'password' ? 'text' : 'password';
            passwordInput.setAttribute('type', type);
            this.textContent = type === 'password' ? '👁️' : '🙈';
        });

        // Checkbox
        const checkbox = document.getElementById('rememberMe');
        let isChecked = false;
        checkbox.addEventListener('click', function() {
            isChecked = !isChecked;
            this.classList.toggle('checked', isChecked);
            document.getElementById('remember_me').value = isChecked ? 1 : 0;
        });

        // Form submit simulation
        const loginForm = document.getElementById('loginForm');
        const loginBtn = document.getElementById('loginBtn');
        loginForm.addEventListener('submit', function(e){
            loginBtn.classList.add('loading');
        });
    </script>
</body>
</html>