<?php

namespace App\Providers;

use App\Services\Push\PushApns;
use App\Services\Push\PushEnRegistro;
use App\Services\Push\PushFirebase;
use App\Services\Push\PushSegunPlataforma;
use App\Services\Push\TransportePush;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        /*
         * POR DÓNDE SALEN LAS NOTIFICACIONES.
         *
         * Con credenciales de Firebase configuradas, por Firebase; sin ellas,
         * por el transporte que solo escribe en el registro y REPORTA FALLO —no
         * éxito—, para que la pantalla diga "0 entregadas, sin transporte
         * configurado" en vez de fingir un envío que no ocurrió.
         *
         * Se decide acá y no dentro del módulo para que el resto del código
         * —segmentar, encolar, contar— no sepa que Firebase existe.
         */
        $this->app->bind(TransportePush::class, function () {
            $firebase = new PushFirebase;

            if (! $firebase->configurado()) {
                return new PushEnRegistro;
            }

            /*
             * Con Firebase configurado siempre se enruta por plataforma:
             * Android por Firebase, iPhone directo a Apple. El enrutador
             * funciona igual aunque APNs no este configurado —los iPhone se
             * quedan sin aviso y los Android siguen recibiendo—, asi que no
             * hace falta decidir aca si Apple esta listo.
             */
            return new PushSegunPlataforma($firebase, new PushApns);
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        /*
         * LAS CARGAS PEREZOSAS REVIENTAN FUERA DE PRODUCCIÓN.
         *
         * Un N+1 no da error: da la respuesta correcta y cien consultas. Es
         * la clase de fallo que no se ve hasta que la tabla crece, y entonces
         * ya está en producción y no se sabe desde cuándo.
         *
         * SE MIDIÓ ANTES DE ENCENDERLO, porque §25 avisa de revisar la
         * compatibilidad con el código heredado y en un proyecto con años
         * encima esto puede romper media suite. Resultado: de 1127 pruebas,
         * **dos** fallaron, y las dos señalaban el mismo N+1 real —el
         * `domiciliary.user` que le faltaba a `GET /orders/business`—. Una
         * vez arreglado, cero. Así que el coste de adoptarlo es cero y lo que
         * atrapa es real.
         *
         * **APAGADO EN PRODUCCIÓN**, que es lo que dice §25 y lo que importa:
         * una carga perezosa que se cuele no le va a tirar la pantalla a un
         * tendero, solo va a costar consultas. El sitio donde tiene que
         * doler es el CI y la máquina de quien lo escribe.
         */
        Model::preventLazyLoading(! app()->isProduction());

        if (app()->environment('production')) {
            URL::forceScheme('https');
            URL::forceRootUrl(config('app.url'));
        }

        // Laravel aplica el limitador 'api' a TODO el grupo api y por defecto
        // es 60/min por usuario/IP: la app dispara ~12 peticiones por arranque
        // más el polling de chats, así que ese presupuesto compartido se
        // agotaba y endpoints como top-businesses recibían 429 (lista de
        // negocios vacía). Este límite alto es solo la red de seguridad
        // global; los límites estrictos por ruta (login, registro, etc.)
        // siguen definidos en routes/api.php.
        RateLimiter::for('api', function (Request $request) {
            return Limit::perMinute(1000)->by(
                $request->user()?->user_id ?: $request->ip(),
            );
        });
    }
}
