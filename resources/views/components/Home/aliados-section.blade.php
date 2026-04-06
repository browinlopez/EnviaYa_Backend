@php
    $partners = $partners ?? [
        ['name' => 'Undeco', 'logo' => asset('landing/assets/img/aliados/undeco.png'), 'link' => '#'],
        ['name' => 'Cámara de Comercio', 'logo' => asset('landing/assets/img/aliados/camaraComercio.png'), 'link' => '#'],
        ['name' => 'Unisimon', 'logo' => asset('landing/assets/img/aliados/unisimon.png'), 'link' => '#'],
       /*  ['name' => 'Aliado 4', 'logo' => asset('landing/assets/img/aliados/undeco.png'), 'link' => '#'],
        ['name' => 'Aliado 5', 'logo' => asset('landing/assets/img/aliados/unisimon.png'), 'link' => '#'], */
    ];
@endphp

<div class="partners-section py-5 bg-gray">
    <div class="container text-center">
        <div class="site-heading mb-5">
            <h5 class="sub-title">Nuestros Aliados</h5>
            <h2>Confían en Nosotros</h2>
            <div class="devider"></div>
        </div>

        <!-- Swiper Carrusel Premium -->
        <div class="swiper partners-swiper-premium">
            <div class="swiper-wrapper align-items-center">
                @foreach ($partners as $partner)
                    <div class="swiper-slide d-flex justify-content-center align-items-center">
                        <a href="{{ $partner['link'] }}" target="_blank" class="partner-logo-wrapper">
                            <img src="{{ $partner['logo'] }}" alt="{{ $partner['name'] }}" class="img-fluid partner-logo">
                        </a>
                    </div>
                @endforeach
            </div>
        </div>
    </div>
</div>

@push('styles')
<style>
    .partner-logo-wrapper {
        display: inline-block;
        transition: transform 0.3s ease, box-shadow 0.3s ease;
    }
    .partner-logo-wrapper:hover {
        transform: scale(1.15);
        box-shadow: 0 10px 20px rgba(0,0,0,0.15);
    }
    .partner-logo {
        max-height: 80px;
        object-fit: contain;
    }
</style>
@endpush