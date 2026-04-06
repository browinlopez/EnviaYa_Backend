<div id="contacto" class="contact-area bg-ligth default-padding"
    style="background-image: url('{{ asset('landing/assets/img/shape/28.png') }}');">
    <div class="container">
        <div class="row align-center">

            <!-- Formulario -->
            <div class="col-tact-stye-one col-lg-7">
                <div class="contact-form-style-one mb-md-50">
                    <h5 class="sub-title">¿Tienes dudas?</h5>
                    <h2 class="heading">Contáctanos</h2>

                    <form action="assets/mail/contact.php" method="POST" class="contact-form contact-form">
                        <div class="row">
                            <div class="col-lg-12">
                                <div class="form-group">
                                    <input class="form-control" id="name" name="name" placeholder="Nombre"
                                        type="text">
                                    <span class="alert-error"></span>
                                </div>
                            </div>
                        </div>

                        <div class="row">
                            <div class="col-lg-6">
                                <div class="form-group">
                                    <input class="form-control" id="email" name="email"
                                        placeholder="Correo electrónico*" type="email">
                                    <span class="alert-error"></span>
                                </div>
                            </div>
                            <div class="col-lg-6">
                                <div class="form-group">
                                    <input class="form-control" id="phone" name="phone" placeholder="Teléfono"
                                        type="text">
                                    <span class="alert-error"></span>
                                </div>
                            </div>
                        </div>

                        <div class="row">
                            <div class="col-lg-12">
                                <div class="form-group comments">
                                    <textarea class="form-control" id="comments" name="comments" placeholder="Cuéntanos en qué podemos ayudarte *"></textarea>
                                </div>
                            </div>
                        </div>

                        <div class="row">
                            <div class="col-lg-12">
                                <button type="submit" name="submit" id="submit">
                                    <i class="fa fa-paper-plane"></i> Enviar mensaje
                                </button>
                            </div>
                        </div>

                        <div class="col-lg-12 alert-notification">
                            <div id="message" class="alert-msg"></div>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Información de contacto -->
            <div class="col-tact-stye-one col-lg-5 pl-60 pl-md-15 pl-xs-15">
                <div class="contact-style-one-info">
                    <h2>
                        Información de
                        <span>
                            contacto
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 500 150" preserveAspectRatio="none">
                                <path
                                    d="M14.4,111.6c0,0,202.9-33.7,471.2,0c0,0-194-8.9-397.3,24.7c0,0,141.9-5.9,309.2,0"
                                    style="animation-play-state: running;"></path>
                            </svg>
                        </span>
                    </h2>

                    <p>
                        En Vecipaya estamos listos para ayudarte. Si eres tendero o cliente,
                        contáctanos y recibe atención rápida y cercana.
                    </p>

                    <ul>
                        <li class="wow fadeInUp">
                            <div class="icon">
                                <i class="fas fa-phone-alt"></i>
                            </div>
                            <div class="content">
                                <h5 class="title">Teléfono</h5>
                                <a href="tel:+573001234567">+57 300 123 4567</a>
                            </div>
                        </li>

                        <li class="wow fadeInUp" data-wow-delay="300ms">
                            <div class="icon">
                                <i class="fas fa-map-marker-alt"></i>
                            </div>
                            <div class="info">
                                <h5 class="title">Zona de operación</h5>
                                <p>
                                    Barrios locales <br> Servicio a domicilio
                                </p>
                            </div>
                        </li>

                        <li class="wow fadeInUp" data-wow-delay="500ms">
                            <div class="icon">
                                <i class="fas fa-envelope-open-text"></i>
                            </div>
                            <div class="info">
                                <h5 class="title">Correo oficial</h5>
                                <a href="mailto:contacto@vecipaya.com">contacto@vecipaya.com</a>
                            </div>
                        </li>
                    </ul>
                </div>
            </div>

        </div>
    </div>
</div>
