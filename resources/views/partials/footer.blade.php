<footer class="bg-dark text-light"
    style="background-image: url('{{ asset('landing/assets/img/shape/brush-down.png') }}');">

    <div class="container">
        <div class="f-items default-padding">
            <div class="row">

                <!-- Sobre Vecipaya -->
                <div class="col-lg-4 col-md-6 item">
                    <div class="footer-item about">
                        <p>
                            Vecipaya es una plataforma de delivery que conecta a las tiendas de barrio
                            con sus vecinos, facilitando compras rápidas, seguras y apoyando el comercio local.
                        </p>
                        <form action="#">
                            <input type="email" placeholder="Tu correo electrónico"
                                class="form-control" name="email">
                            <button type="submit" style="color: white;">></button>
                        </form>
                    </div>
                </div>

                <!-- Enlaces -->
                <div class="col-lg-2 col-md-6 item">
                    <div class="footer-item link">
                        <h4 class="widget-title">Explorar</h4>
                        <ul>
                            <li><a href="#">Inicio</a></li>
                            <li><a href="#">¿Quiénes somos?</a></li>
                            <li><a href="#">Tiendas aliadas</a></li>
                            <li><a href="#">Cómo funciona</a></li>
                            <li><a href="#">Contacto</a></li>
                        </ul>
                    </div>
                </div>

                <!-- Publicaciones recientes -->
                <div class="col-lg-3 col-md-6 item">
                    <div class="footer-item recent-post">
                        <h4 class="widget-title">Blog Vecipaya</h4>
                        <ul>
                            <li>
                                <div class="thumb">
                                    <a href="#">
                                        <img src="{{ asset('landing/assets/img/blog/2-2.jpg') }}" alt="Thumb">
                                    </a>
                                </div>
                                <div class="info">
                                    <span class="post-date">10 Sep, 2025</span>
                                    <h5>
                                        <a href="#">Cómo Vecipaya apoya a las tiendas de barrio</a>
                                    </h5>
                                </div>
                            </li>
                            <li>
                                <div class="thumb">
                                    <a href="#">
                                        <img src="{{ asset('landing/assets/img/blog/2-2.jpg') }}" alt="Thumb">
                                    </a>
                                </div>
                                <div class="info">
                                    <span class="post-date">02 Sep, 2025</span>
                                    <h5>
                                        <a href="#">Beneficios del delivery local en tu barrio</a>
                                    </h5>
                                </div>
                            </li>
                        </ul>
                    </div>
                </div>

                <!-- Contacto -->
                <div class="col-lg-3 col-md-6 item">
                    <div class="footer-item contact">
                        <h4 class="widget-title">Contacto</h4>
                        <ul>
                            <li>
                                <i class="fas fa-map-marker-alt"></i>
                                <strong>Zona:</strong> Servicio en barrios locales
                            </li>
                            <li>
                                <i class="fas fa-envelope"></i>
                                <strong>Email:</strong>
                                <a href="mailto:contacto@vecipaya.com">contacto@vecipaya.com</a>
                            </li>
                            <li>
                                <i class="fas fa-phone"></i>
                                <strong>Teléfono:</strong>
                                <a href="tel:+573001234567">+57 300 123 4567</a>
                            </li>
                        </ul>
                    </div>
                </div>

            </div>
        </div>

        <!-- Footer Bottom -->
        <div class="footer-bottom">
            <div class="row">
                <div class="col-lg-6">
                    <p style="color:#1B1464;">
                        &copy; 2025 Vecipaya. Todos los derechos reservados.
                    </p>
                </div>
                <div class="col-lg-6 text-end">
                    <ul>
                        <li><a href="#" style="color:#1B1464;">Términos</a></li>
                        <li><a href="#" style="color:#1B1464;">Privacidad</a></li>
                        <li><a href="#" style="color:#1B1464;">Soporte</a></li>
                    </ul>
                </div>
            </div>
        </div>
    </div>

    <!-- Shapes -->
    <div class="shape-right-bottom">
        <img src="{{ asset('landing/assets/img/shape/10.png') }}" alt="">
    </div>
    <div class="shape-left-bottom">
        <img src="{{ asset('landing/assets/img/shape/11.png') }}" alt="" style="opacity:0.2;">
    </div>

</footer>