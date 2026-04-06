@php
    // Valores por defecto para Vecipaya
    $appName = $appName ?? 'Vecipaya';
    $googlePlayLink = $googlePlayLink ?? 'https://play.google.com/store/apps/details?id=com.browinlopez.EnviaYa&pcampaignid=web_share';
    $appStoreLink = $appStoreLink ?? 'https://apps.apple.com/co/app/vecipaya/id6753903974';
    $description = $description ?? 'Compra en tu barrio sin salir de casa. Descarga Vecipaya y recibe tus productos favoritos directamente en tu hogar.';
@endphp

<div class="download-section py-5 bg-gray">
    <div class="container">
        <div class="row">
            <div class="col-lg-8 offset-lg-2 text-center">
                <div class="site-heading mb-4">
                    <h5 class="sub-title">Descarga la Aplicación</h5>
                    <h2>Accede a {{ $appName }} Desde tu Dispositivo Móvil</h2>
                    <div class="devider"></div>
                    <p class="mt-3 text-muted">{{ $description }}</p>
                </div>
            </div>
        </div>

        <div class="row justify-content-center align-items-center mt-4">
            <!-- Google Play -->
            <div class="col-md-5 text-center mb-4 mb-md-0">
                <div class="download-item p-4 bg-white shadow-sm rounded">
                    <div class="download-icon mb-3">
                        <i class="fab fa-google-play fa-3x text-success"></i>
                    </div>
                    <h5 class="fw-bold">Disponible en Google Play</h5>
                    <p class="text-muted mb-3">Descarga la aplicación para dispositivos Android.</p>
                    <a href="{{ $googlePlayLink }}" 
                       target="_blank" 
                       class="btn btn-dark btn-lg px-4">
                        <i class="fab fa-android me-2"></i> Google Play
                    </a>
                </div>
            </div>

            <!-- App Store -->
            <div class="col-md-5 text-center">
                <div class="download-item p-4 bg-white shadow-sm rounded">
                    <div class="download-icon mb-3">
                        <i class="fab fa-apple fa-3x text-dark"></i>
                    </div>
                    <h5 class="fw-bold">Disponible en App Store</h5>
                    <p class="text-muted mb-3">Descarga la aplicación para dispositivos iOS.</p>
                    <a href="{{ $appStoreLink }}" 
                       target="_blank" 
                       class="btn btn-dark btn-lg px-4">
                        <i class="fab fa-apple me-2"></i> App Store
                    </a>
                </div>
            </div>
        </div>
    </div>
</div>