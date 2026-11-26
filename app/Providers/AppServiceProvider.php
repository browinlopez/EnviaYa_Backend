<?php

declare(strict_types=1);

namespace App\Providers;

use App\Models\Conjunto\ComplexStaff;
use App\Models\Domiciliary;
use App\Models\Order\OrdersSales;
use App\Models\User;
use App\Policies\DomiciliaryPolicy;
use App\Policies\OrdersSalesPolicy;
use App\Services\Push\PushApns;
use App\Services\Push\PushEnRegistro;
use App\Services\Push\PushFirebase;
use App\Services\Push\PushSegunPlataforma;
use App\Services\Push\TransportePush;
use App\Support\ContadorDeConsultas;
use App\Support\DestinoDelRespaldo;
use App\Support\PresupuestoDeConsultas;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;
use Spatie\Backup\Events\DumpingDatabase;

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
        // Avisa en el canal `rendimiento` cuando una peticion pasa del
        // presupuesto de tiempo en base de datos. El porque, en la clase.
        PresupuestoDeConsultas::vigilar((float) config('database.presupuesto_ms'));

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

        /*
         * LAS POLICIES, REGISTRADAS A MANO AUNQUE NO HAGA FALTA.
         *
         * AQUI HABIA ESCRITO QUE EL DESCUBRIMIENTO AUTOMATICO NO LAS
         * ENCONTRARIA, por vivir los modelos en subcarpeta de dominio. ES
         * FALSO, y lo canto un sabotaje: comentando el registro, la prueba
         * siguio en verde. El buscador de Laravel SUBE por el espacio de
         * nombres --para `App\\Models\\Order\\OrdersSales` prueba
         * `App\\Models\\Order\\Policies\\...`, luego
         * `App\\Models\\Policies\\...` y luego
         * `App\\Policies\\OrdersSalesPolicy`, que es donde estan--.
         *
         * Se dejan registradas por lo que SI es verdad: §08 pide saber que
         * recurso tiene regla, y esta lista lo dice en dos lineas sin que
         * nadie tenga que conocer el algoritmo del buscador. Es documentacion
         * ejecutable, no una necesidad tecnica.
         *
         * SEGUNDA CAPA, NO SUSTITUTO: el middleware de grupo sigue donde esta
         * y es la primera linea, por lo que razona `EXCEPCIONES.md` --el
         * middleware falla cerrado; una Policy olvidada falla abierta--. Esto
         * es la defensa en profundidad de §35.
         */
        Gate::policy(Domiciliary::class, DomiciliaryPolicy::class);
        Gate::policy(OrdersSales::class, OrdersSalesPolicy::class);

        /*
         * Y DOS PUERTAS CON NOMBRE, para las reglas cuyo argumento NO es el
         * modelo. §08 admite «Policy o Gate», y aquí hacen falta las dos:
         *
         *   · `ver-ubicacion-del-domiciliario` recibe un `domiciliary_id`,
         *     que es lo único que trae la petición del seguimiento. Cargar el
         *     modelo para que el Gate pudiera resolver la Policy sería una
         *     consulta por nada en la pantalla que la app refresca mientras
         *     reparte.
         *   · `ver-y-editar-domiciliario` recibe el USUARIO objetivo, y el
         *     objetivo puede no tener fila de domiciliario: la regla dice que
         *     uno siempre puede verse a sí mismo, incluso entonces.
         *
         * LA LÓGICA NO SE DUPLICA: las dos delegan en los métodos de
         * `DomiciliaryPolicy`, que es donde vive la regla. Esto solo les pone
         * un nombre invocable desde cualquier parte.
         */
        Gate::define(
            'ver-ubicacion-del-domiciliario',
            fn (?User $usuario, int $domiciliaryId) => app(DomiciliaryPolicy::class)
                ->verUbicacion($usuario, $domiciliaryId),
        );

        Gate::define(
            'ver-y-editar-domiciliario',
            fn (?User $usuario, User $objetivo) => app(DomiciliaryPolicy::class)
                ->verYEditar($usuario, $objetivo),
        );

        /*
         * El contador de consultas por peticion: UNA vez por trabajador, no
         * una por peticion. Registrarlo en el middleware acumularia un oyente
         * por cada peticion bajo Octane, y a la milesima cada consulta
         * llamaria a mil closures. Ver `ContadorDeConsultas`.
         */
        ContadorDeConsultas::registrar();

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

        /*
         * LA VERIFICACIÓN DE LA PORTERÍA, POR CONJUNTO.
         *
         * El `throttle:40,1` de la ruta es por usuario, y un conjunto puede
         * tener varios celadores: con diez cuentas se probaban cuatrocientas
         * cédulas por minuto contra la misma puerta. Este tope es del
         * EDIFICIO entero. Una portería real no verifica dos personas por
         * segundo sostenidas.
         *
         * El conjunto lo deja puesto `EnsureComplexStaff`, que corre antes;
         * si por algún motivo no estuviera, se resuelve desde la ficha.
         */
        RateLimiter::for('porteria-conjunto', function (Request $request) {
            $conjunto = $request->attributes->get('complex_id')
                ?? ComplexStaff::de($request->user()?->user_id)?->complex_id;

            return Limit::perMinute(120)->by('conjunto:'.($conjunto ?: $request->ip()));
        });

        /*
         * LA COPIA DE SEGURIDAD NO SE SUBE A UN BUCKET PÚBLICO.
         *
         * Se engancha en `DumpingDatabase`, que spatie dispara ANTES de volcar
         * la base: así aborta sin haber escrito nada. La excepción la recoge
         * el propio `BackupJob` y la convierte en «respaldo fallido», que es
         * lo que manda el aviso por correo. El síntoma pasa a ser un mensaje
         * que dice qué está mal, en vez de un aviso diario diciendo que todo
         * va bien mientras la base entera cuelga de una URL.
         *
         * El porqué, en `DestinoDelRespaldo`.
         */
        Event::listen(DumpingDatabase::class, function (): void {
            DestinoDelRespaldo::comprobar();
        });
    }
}
