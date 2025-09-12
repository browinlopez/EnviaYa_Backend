<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>EnviaYa - Tu Delivery de Barrio</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            line-height: 1.6;
            color: #333;
            overflow-x: hidden;
        }

        .container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 0 20px;
        }

        /* Navbar */
        .navbar {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            background: rgba(27, 20, 100, 0.95);
            backdrop-filter: blur(20px);
            z-index: 1000;
            transition: all 0.3s ease;
            border-bottom: 1px solid rgba(255, 255, 255, 0.1);
        }

        .navbar.scrolled {
            background: rgba(27, 20, 100, 0.98);
            box-shadow: 0 5px 30px rgba(27, 20, 100, 0.3);
        }

        .nav-container {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 15px 20px;
            max-width: 1200px;
            margin: 0 auto;
        }

        .logo {
            display: flex;
            align-items: center;
            gap: 10px;
            color: white;
            text-decoration: none;
            font-size: 1.5rem;
            font-weight: 800;
        }

        .logo-icon {
            width: 40px;
            height: 40px;
            border-radius: 10px;
            object-fit: contain;
        }

        @keyframes pulse-logo {

            0%,
            100% {
                transform: scale(1);
            }

            50% {
                transform: scale(1.05);
            }
        }

        .nav-menu {
            display: flex;
            list-style: none;
            gap: 30px;
            align-items: center;
        }

        .nav-link {
            color: rgba(255, 255, 255, 0.9);
            text-decoration: none;
            font-weight: 500;
            transition: all 0.3s ease;
            position: relative;
        }

        .nav-link::after {
            content: '';
            position: absolute;
            bottom: -5px;
            left: 0;
            width: 0;
            height: 2px;
            background: linear-gradient(135deg, #f59e0b, #ef4444);
            transition: width 0.3s ease;
        }

        .nav-link:hover::after {
            width: 100%;
        }

        .nav-link:hover {
            color: #f59e0b;
        }

        .nav-login-btn {
            background: linear-gradient(135deg, #f59e0b, #ef4444);
            color: white;
            padding: 10px 20px;
            border-radius: 25px;
            text-decoration: none;
            font-weight: 600;
            transition: all 0.3s ease;
            box-shadow: 0 5px 15px rgba(245, 158, 11, 0.3);
        }

        .nav-login-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(245, 158, 11, 0.4);
        }

        .mobile-menu-toggle {
            display: none;
            background: none;
            border: none;
            color: white;
            font-size: 1.5rem;
            cursor: pointer;
        }

        /* Hero Section */
        .hero {
            background: linear-gradient(135deg, #1B1464 0%, #2d1b8e 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            position: relative;
            overflow: hidden;
            padding-top: 80px;
        }

        .hero::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: url("data:image/svg+xml,%3Csvg width='60' height='60' viewBox='0 0 60 60' xmlns='http://www.w3.org/2000/svg'%3E%3Cg fill='none' fill-rule='evenodd'%3E%3Cg fill='%23ffffff' fill-opacity='0.03'%3E%3Ccircle cx='30' cy='30' r='2'/%3E%3C/g%3E%3C/g%3E%3C/svg%3E") repeat;
            animation: float 20s ease-in-out infinite;
        }

        @keyframes float {

            0%,
            100% {
                transform: translateY(0px);
            }

            50% {
                transform: translateY(-20px);
            }
        }

        .hero-content {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 60px;
            align-items: center;
            z-index: 2;
            position: relative;
        }

        .hero-text {
            color: white;
            animation: slideInLeft 1s ease-out;
        }

        .hero-text h1 {
            font-size: 3.5rem;
            font-weight: 800;
            margin-bottom: 20px;
            background: linear-gradient(135deg, #a78bfa, #a78bfa);
            background-clip: text;
            -webkit-background-clip: text;
            color: transparent;
            line-height: 1.1;
        }

        .hero-text p {
            font-size: 1.3rem;
            margin-bottom: 30px;
            opacity: 0.9;
            font-weight: 300;
        }

        .hero-visual {
            position: relative;
            animation: slideInRight 1s ease-out;
        }

        .delivery-illustration {
            width: 100%;
            height: 400px;
            background: url('vendor/adminlte/dist/img/domi.jpeg'), linear-gradient(135deg, #4f46e5, #7c3aed);
            background-size: contain;
            background-repeat: no-repeat;
            background-position: center;
            border-radius: 20px;
            position: relative;
            overflow: hidden;
            box-shadow: 0 25px 50px rgba(0, 0, 0, 0.3);
            animation: pulse 3s ease-in-out infinite alternate;
        }


        @keyframes pulse {
            0% {
                transform: scale(1);
            }

            100% {
                transform: scale(1.05);
            }
        }

        @keyframes bounce {

            0%,
            100% {
                transform: translate(-50%, -50%) scale(1);
            }

            50% {
                transform: translate(-50%, -50%) scale(1.1);
            }
        }

        @keyframes slideInLeft {
            from {
                opacity: 0;
                transform: translateX(-50px);
            }

            to {
                opacity: 1;
                transform: translateX(0);
            }
        }

        @keyframes slideInRight {
            from {
                opacity: 0;
                transform: translateX(50px);
            }

            to {
                opacity: 1;
                transform: translateX(0);
            }
        }

        @keyframes fadeInUp {
            from {
                opacity: 0;
                transform: translateY(30px);
            }

            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .btn-primary {
            display: inline-block;
            background: linear-gradient(135deg, #f59e0b, #ef4444);
            color: white;
            padding: 18px 35px;
            text-decoration: none;
            border-radius: 50px;
            font-weight: 600;
            font-size: 1.1rem;
            transition: all 0.3s ease;
            box-shadow: 0 10px 30px rgba(245, 158, 11, 0.3);
            position: relative;
            overflow: hidden;
        }

        .btn-primary::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(135deg, rgba(255, 255, 255, 0.2), transparent);
            transition: left 0.5s;
        }

        .btn-primary:hover::before {
            left: 100%;
        }

        .btn-primary:hover {
            transform: translateY(-3px);
            box-shadow: 0 15px 40px rgba(245, 158, 11, 0.4);
        }

        /* About Section */
        .about {
            padding: 100px 0;
            background: linear-gradient(135deg, #f8fafc, #e2e8f0);
        }

        .about-content {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 60px;
            align-items: center;
        }

        .about-text h2 {
            font-size: 2.5rem;
            color: #1B1464;
            margin-bottom: 30px;
            font-weight: 800;
        }

        .about-text p {
            font-size: 1.2rem;
            color: #64748b;
            line-height: 1.8;
            margin-bottom: 30px;
        }

        .about-visual {
            position: relative;
        }

        .about-image {
            width: 100%;
            height: 350px;
            background: linear-gradient(135deg, #1B1464, #4f46e5);
            border-radius: 20px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 4rem;
            color: white;
            box-shadow: 0 20px 40px rgba(27, 20, 100, 0.2);
            animation: float-gentle 6s ease-in-out infinite;
        }

        @keyframes float-gentle {

            0%,
            100% {
                transform: translateY(0);
            }

            50% {
                transform: translateY(-10px);
            }
        }

        /* App Gallery Section */
        .app-gallery {
            padding: 100px 0;
            background: white;
        }

        .section-title {
            text-align: center;
            font-size: 2.5rem;
            font-weight: 800;
            color: #1B1464;
            margin-bottom: 20px;
        }

        .section-subtitle {
            text-align: center;
            font-size: 1.2rem;
            color: #64748b;
            max-width: 600px;
            margin: 0 auto 60px;
        }

        .gallery-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(400px, 1fr));
            gap: 40px;
            margin-top: 60px;
        }

        .gallery-item {
            background: white;
            border-radius: 20px;
            overflow: hidden;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.1);
            transition: all 0.3s ease;
            animation: fadeInUp 0.8s ease-out;
        }

        .gallery-item:hover {
            transform: translateY(-10px);
            box-shadow: 0 20px 40px rgba(27, 20, 100, 0.15);
        }

        .gallery-image {
            width: 100%;
            height: 200px;
            background: linear-gradient(135deg, #1B1464, #4f46e5);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 3rem;
            color: white;
        }

        .gallery-content {
            padding: 25px;
        }

        .gallery-content h3 {
            font-size: 1.3rem;
            color: #1B1464;
            margin-bottom: 10px;
            font-weight: 700;
        }

        .gallery-content p {
            color: #64748b;
            line-height: 1.6;
        }

        /* Contact Section */
        .contact {
            padding: 100px 0;
            background: linear-gradient(135deg, #f8fafc, #e2e8f0);
        }

        .contact-content {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 60px;
            align-items: start;
        }

        .contact-form {
            background: white;
            padding: 40px;
            border-radius: 20px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.1);
        }

        .form-group {
            margin-bottom: 25px;
        }

        .form-label {
            display: block;
            margin-bottom: 8px;
            color: #1B1464;
            font-weight: 600;
        }

        .form-input,
        .form-textarea {
            width: 100%;
            padding: 15px;
            border: 2px solid #e2e8f0;
            border-radius: 10px;
            font-size: 1rem;
            transition: all 0.3s ease;
            background: #f8fafc;
        }

        .form-input:focus,
        .form-textarea:focus {
            outline: none;
            border-color: #1B1464;
            background: white;
            box-shadow: 0 0 10px rgba(27, 20, 100, 0.1);
        }

        .form-textarea {
            resize: vertical;
            min-height: 120px;
        }

        .btn-submit {
            background: linear-gradient(135deg, #1B1464, #4f46e5);
            color: white;
            padding: 15px 30px;
            border: none;
            border-radius: 50px;
            font-size: 1.1rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            width: 100%;
        }

        .btn-submit:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 25px rgba(27, 20, 100, 0.3);
        }

        .contact-info h2 {
            font-size: 2.5rem;
            color: #1B1464;
            margin-bottom: 30px;
            font-weight: 800;
        }

        .contact-info p {
            font-size: 1.2rem;
            color: #64748b;
            line-height: 1.8;
            margin-bottom: 30px;
        }

        .contact-details {
            display: flex;
            flex-direction: column;
            gap: 20px;
        }

        .contact-item {
            display: flex;
            align-items: center;
            gap: 15px;
            padding: 15px;
            background: white;
            border-radius: 10px;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.05);
        }

        .contact-icon {
            width: 50px;
            height: 50px;
            background: linear-gradient(135deg, #1B1464, #4f46e5);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
            color: white;
        }

        /* Footer */
        .footer {
            background: #0f172a;
            color: white;
            padding: 60px 0 20px;
            text-align: center;
        }

        .footer-content {
            margin-bottom: 40px;
        }

        .footer-content h3 {
            font-size: 1.8rem;
            color: #fff;
            margin-bottom: 20px;
            font-weight: 800;
        }

        .footer-content p {
            font-size: 1.2rem;
            color: #94a3b8;
            line-height: 1.6;
            margin-bottom: 30px;
        }

        .footer-bottom {
            border-top: 1px solid #334155;
            padding-top: 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 20px;
        }

        .social-links {
            display: flex;
            gap: 15px;
            align-items: center;
        }

        .social-link {
            width: 50px;
            height: 50px;
            background: rgba(255, 255, 255, 0.1);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            text-decoration: none;
            font-size: 1.2rem;
            transition: all 0.3s ease;
        }

        .social-link:hover {
            background: #1B1464;
            transform: translateY(-3px);
        }

        .btn-footer-login {
            background: #1B1464;
            color: white;
            padding: 15px 30px;
            text-decoration: none;
            border-radius: 25px;
            font-weight: 600;
            transition: all 0.3s ease;
            border: 2px solid #1B1464;
        }

        .btn-footer-login:hover {
            background: transparent;
            color: #1B1464;
            border-color: #1B1464;
            transform: translateY(-2px);
        }

        /* WhatsApp Floating Button */
        .whatsapp-float {
            position: fixed;
            bottom: 30px;
            right: 30px;
            background: #25d366;
            color: white;
            width: 60px;
            height: 60px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
            text-decoration: none;
            box-shadow: 0 10px 30px rgba(37, 211, 102, 0.4);
            z-index: 1000;
            animation: pulse-whatsapp 2s infinite;
            transition: all 0.3s ease;
        }

        .whatsapp-float:hover {
            transform: scale(1.1);
            box-shadow: 0 15px 40px rgba(37, 211, 102, 0.5);
        }

        @keyframes pulse-whatsapp {
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

        .whatsapp-tooltip {
            position: absolute;
            right: 70px;
            top: 50%;
            transform: translateY(-50%);
            background: #333;
            color: white;
            padding: 8px 12px;
            border-radius: 8px;
            font-size: 0.9rem;
            white-space: nowrap;
            opacity: 0;
            visibility: hidden;
            transition: all 0.3s ease;
        }

        .whatsapp-float:hover .whatsapp-tooltip {
            opacity: 1;
            visibility: visible;
            right: 75px;
        }

        /* Scroll animations */
        .animate-on-scroll {
            opacity: 0;
            transform: translateY(30px);
            transition: all 0.8s ease;
        }

        .animate-on-scroll.animated {
            opacity: 1;
            transform: translateY(0);
        }

        /* Responsive Design */
        @media (max-width: 768px) {
            .mobile-menu-toggle {
                display: block;
            }

            .nav-menu {
                position: fixed;
                top: 80px;
                left: -100%;
                width: 100%;
                height: calc(100vh - 80px);
                background: rgba(27, 20, 100, 0.98);
                flex-direction: column;
                justify-content: start;
                padding-top: 50px;
                transition: left 0.3s ease;
            }

            .nav-menu.active {
                left: 0;
            }

            .hero-content {
                grid-template-columns: 1fr;
                text-align: center;
                gap: 40px;
            }

            .hero-text h1 {
                font-size: 2.5rem;
            }

            .about-content,
            .contact-content {
                grid-template-columns: 1fr;
                gap: 40px;
            }

            .gallery-grid {
                grid-template-columns: 1fr;
                gap: 30px;
            }

            .section-title {
                font-size: 2rem;
            }

            .footer-bottom {
                flex-direction: column;
                text-align: center;
            }

            .whatsapp-float {
                bottom: 20px;
                right: 20px;
                width: 55px;
                height: 55px;
            }
        }
    </style>
</head>

<body>
    <!-- Navbar -->
    <nav class="navbar" id="navbar">
        <div class="nav-container">
            <a href="#" class="logo">
                <img src="{{ asset('vendor/adminlte/dist/img/Logo.png') }}" alt="EnviaYa" class="logo-icon">nviaYa
            </a>

            <ul class="nav-menu" id="nav-menu">
                <li><a href="#inicio" class="nav-link">Inicio</a></li>
                <li><a href="#nosotros" class="nav-link">Nosotros</a></li>
                <li><a href="#app" class="nav-link">La App</a></li>
                <li><a href="#contacto" class="nav-link">Contacto</a></li>
                <li><a href="{{ route('dashboard') }}" class="nav-login-btn">Iniciar Sesión</a></li>
            </ul>
            <button class="mobile-menu-toggle" id="mobile-toggle">
                ☰
            </button>
        </div>
    </nav>

    <!-- Hero Section -->
    <section class="hero" id="inicio">
        <div class="container">
            <div class="hero-content">
                <div class="hero-text">
                    <h1>EnviaYa – Tu Delivery de Barrio, Más Fácil y Seguro</h1>
                    <p>Llega a tus clientes de manera rápida, mejora tus ventas y fideliza a tu comunidad. EnviaYa es tu
                        aliado en el crecimiento de tu negocio local.</p>
                    <a href="{{ route('dashboard') }}" class="btn-primary">Iniciar Sesión</a>
                </div>
                <div class="hero-visual">
                    <div class="delivery-illustration">
                        <img src="{{ asset('vendor/adminlte/dist/img/domi.jpg') }}" alt="Delivery" class="delivery-icon">
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- About Section -->
    <section class="about" id="nosotros">
        <div class="container">
            <div class="about-content animate-on-scroll">
                <div class="about-text">
                    <h2>Conoce EnviaYa</h2>
                    <p>EnviaYa es una aplicación móvil diseñada especialmente para los negocios de barrio. Nuestro
                        objetivo es ayudarte a vender más, mantener a tus clientes felices y garantizar la seguridad de
                        cada entrega.</p>
                    <p>Somos tu amigo digital que te acompaña en cada paso para que tu negocio crezca y se conecte mejor
                        con tu comunidad.</p>
                </div>
                <div class="about-visual">
                    <div class="about-image">📱</div>
                </div>
            </div>
        </div>
    </section>

    <!-- App Gallery Section -->
    <section class="app-gallery" id="app">
        <div class="container">
            <h2 class="section-title animate-on-scroll">Descubre la App</h2>
            <p class="section-subtitle animate-on-scroll">Explora cómo EnviaYa hace que recibir y enviar pedidos sea más
                fácil y seguro. Con una interfaz simple y amigable, tu negocio estará a un clic de tus clientes.</p>

            <div class="gallery-grid">
                <div class="gallery-item animate-on-scroll">
                    <div class="gallery-image">📋</div>
                    <div class="gallery-content">
                        <h3>Pantalla principal de pedidos</h3>
                        <p>Visualiza todos tus pedidos de manera organizada y gestiona cada entrega con facilidad.</p>
                    </div>
                </div>

                <div class="gallery-item animate-on-scroll">
                    <div class="gallery-image">👥</div>
                    <div class="gallery-content">
                        <h3>Gestión de clientes y ventas</h3>
                        <p>Mantén un registro detallado de tus clientes y aumenta tus ventas con herramientas
                            intuitivas.</p>
                    </div>
                </div>

                <div class="gallery-item animate-on-scroll">
                    <div class="gallery-image">📍</div>
                    <div class="gallery-content">
                        <h3>Rastreo de entregas en tiempo real</h3>
                        <p>Sigue cada entrega en vivo y mantén informados a tus clientes sobre el estado de sus pedidos.
                        </p>
                    </div>
                </div>

                <div class="gallery-item animate-on-scroll">
                    <div class="gallery-image">🔔</div>
                    <div class="gallery-content">
                        <h3>Notificaciones para mantener todo bajo control</h3>
                        <p>Recibe alertas importantes y nunca pierdas un pedido con nuestro sistema de notificaciones.
                        </p>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- Contact Section -->
    <section class="contact" id="contacto">
        <div class="container">
            <div class="contact-content">
                <div class="contact-info animate-on-scroll">
                    <h2>Estamos para ayudarte</h2>
                    <p>¿Tienes dudas o quieres más información sobre cómo EnviaYa puede potenciar tu negocio? Escríbenos
                        y te responderemos de inmediato.</p>

                    <div class="contact-details">
                        <div class="contact-item">
                            <div class="contact-icon">📧</div>
                            <div>
                                <strong>Email</strong><br>
                                hola@enviaya.com
                            </div>
                        </div>

                        <div class="contact-item">
                            <div class="contact-icon">📱</div>
                            <div>
                                <strong>Teléfono</strong><br>
                                +57 300 123 4567
                            </div>
                        </div>

                        <div class="contact-item">
                            <div class="contact-icon">⏰</div>
                            <div>
                                <strong>Horario</strong><br>
                                Lun - Vie: 8:00 AM - 6:00 PM
                            </div>
                        </div>
                    </div>
                </div>

                <div class="contact-form animate-on-scroll">
                    <form id="contactForm">
                        <div class="form-group">
                            <label for="nombre" class="form-label">Nombre</label>
                            <input type="text" id="nombre" class="form-input" required>
                        </div>

                        <div class="form-group">
                            <label for="email" class="form-label">Correo electrónico</label>
                            <input type="email" id="email" class="form-input" required>
                        </div>

                        <div class="form-group">
                            <label for="mensaje" class="form-label">Mensaje</label>
                            <textarea id="mensaje" class="form-textarea" required></textarea>
                        </div>

                        <button type="submit" class="btn-submit">Enviar Mensaje</button>
                    </form>
                </div>
            </div>
        </div>
    </section>

    <!-- Footer -->
    <footer class="footer">
        <div class="container">
            <div class="footer-content">
                <h3>Únete a EnviaYa</h3>
                <p>Únete a EnviaYa y lleva tu negocio al siguiente nivel. Todo empieza con una cuenta.</p>
                <a href="{{ route('dashboard') }}" class="btn-primary">Iniciar Sesión</a>
            </div>

            <div class="footer-bottom">
                <div>
                    <p>&copy; 2025 EnviaYa. Todos los derechos reservados.</p>
                    <p>Síguenos en nuestras redes y mantente al día con nuestras novedades.</p>
                </div>
                <div class="social-links">
                    <a href="#" class="social-link">📘</a>
                    <a href="#" class="social-link">📷</a>
                    <a href="#" class="social-link">🐦</a>
                    <a href="#" class="social-link">💼</a>
                </div>
            </div>

            <script>
                const animateElements = document.querySelectorAll('.animate-on-scroll');

                function handleScroll() {
                    animateElements.forEach(el => {
                        const rect = el.getBoundingClientRect();
                        if (rect.top < window.innerHeight - 100) {
                            el.classList.add('animated');
                        }
                    });
                }

                window.addEventListener('scroll', handleScroll);
                window.addEventListener('load', handleScroll);
            </script>
