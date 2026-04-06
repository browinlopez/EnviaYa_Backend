{{-- Core --}}
<script src="{{ asset('landing/assets/js/jquery-3.6.0.min.js') }}"></script>
<script src="{{ asset('landing/assets/js/bootstrap.bundle.min.js') }}"></script>

{{-- Plugins --}}
<script src="{{ asset('landing/assets/js/jquery.appear.js') }}"></script>
<script src="{{ asset('landing/assets/js/jquery.easing.min.js') }}"></script>
<script src="{{ asset('landing/assets/js/jquery.magnific-popup.min.js') }}"></script>
<script src="{{ asset('landing/assets/js/modernizr.custom.13711.js') }}"></script>
<script src="{{ asset('landing/assets/js/swiper-bundle.min.js') }}"></script>
<script src="{{ asset('landing/assets/js/wow.min.js') }}"></script>
<script src="{{ asset('landing/assets/js/progress-bar.min.js') }}"></script>
<script src="{{ asset('landing/assets/js/circle-progress.js') }}"></script>
<script src="{{ asset('landing/assets/js/isotope.pkgd.min.js') }}"></script>
<script src="{{ asset('landing/assets/js/imagesloaded.pkgd.min.js') }}"></script>
<script src="{{ asset('landing/assets/js/jquery.nice-select.min.js') }}"></script>
<script src="{{ asset('landing/assets/js/count-to.js') }}"></script>
<script src="{{ asset('landing/assets/js/jquery.scrolla.min.js') }}"></script>
<script src="{{ asset('landing/assets/js/YTPlayer.min.js') }}"></script>
<script src="{{ asset('landing/assets/js/TweenMax.min.js') }}"></script>
<script src="{{ asset('landing/assets/js/validnavs.js') }}"></script>

{{-- Main --}}
<script src="{{ asset('landing/assets/js/main.js') }}"></script>

<script>
    document.addEventListener('DOMContentLoaded', function () {
        new Swiper('.partners-swiper-premium', {
            slidesPerView: 4,
            spaceBetween: 30,
            loop: true,
            autoplay: {
                delay: 0, // scroll continuo
                disableOnInteraction: false,
                speed: 3000,
            },
            speed: 3000,
            freeMode: true,
            freeModeMomentum: false,
            breakpoints: {
                320: { slidesPerView: 2 },
                576: { slidesPerView: 3 },
                768: { slidesPerView: 4 },
                992: { slidesPerView: 5 },
                1200: { slidesPerView: 6 },
            },
        });
    });
</script>